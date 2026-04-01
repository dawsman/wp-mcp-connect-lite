import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import React from 'react';
import { Button, SelectControl, Notice, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { external } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';
import { Card, Badge, MetricCard, SkeletonLoader, EmptyState } from './ui';

const SEOAudit = () => {
    const [posts, setPosts] = useState([]);
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [postType, setPostType] = useState('any');
    const [missingField, setMissingField] = useState('');
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [postTypes, setPostTypes] = useState([]);
    const [expandedRows, setExpandedRows] = useState(new Set());
    const [bulkUpdates, setBulkUpdates] = useState({});
    const [schemaMode, setSchemaMode] = useState('replace');
    const [bulkSaving, setBulkSaving] = useState(false);
    const [suggesting, setSuggesting] = useState({});

    useEffect(() => {
        loadPostTypes();
    }, []);

    useEffect(() => {
        loadAuditData();
    }, [postType, missingField, page]);

    const loadPostTypes = async () => {
        try {
            const types = await apiFetch({ path: '/wp/v2/types' });
            const publicTypes = Object.values(types)
                .filter((type) => type.visibility?.show_in_nav_menus)
                .map((type) => ({ value: type.slug, label: type.name }));
            setPostTypes([{ value: 'any', label: __('All Post Types', 'wp-mcp-connect') }, ...publicTypes]);
        } catch {
            setPostTypes([{ value: 'any', label: __('All Post Types', 'wp-mcp-connect') }]);
        }
    };

    const loadAuditData = async () => {
        setLoading(true);
        setError(null);

        try {
            let url = `/mcp/v1/seo/audit?post_type=${postType}&page=${page}&per_page=20`;
            if (missingField) {
                url += `&missing_fields[]=${missingField}`;
            }

            const data = await apiFetch({ path: url });
            setPosts(data.results || []);
            setSummary(data.summary || {});
            setTotalPages(data.total_pages || 1);
        } catch (err) {
            setError(err.message || 'Failed to load SEO audit data');
        } finally {
            setLoading(false);
        }
    };

    const updateBulkField = (postId, field, value) => {
        setBulkUpdates((prev) => ({
            ...prev,
            [postId]: {
                ...prev[postId],
                [field]: value,
            },
        }));
    };

    const mergeSchema = useCallback((existingStr, newStr) => {
        const parseSchema = (value) => {
            if (!value) return [];
            try {
                const parsed = JSON.parse(value);
                return Array.isArray(parsed) ? parsed : [parsed];
            } catch {
                return [];
            }
        };

        const existing = parseSchema(existingStr);
        const incoming = parseSchema(newStr);
        if (!incoming.length) {
            return existingStr;
        }

        const existingTypes = new Set(existing.map((item) => item?.['@type']));
        for (const item of incoming) {
            const itemType = item?.['@type'];
            if (itemType && existingTypes.has(itemType)) {
                throw new Error(`Schema type '${itemType}' already exists.`);
            }
            existing.push(item);
        }

        const finalValue = existing.length === 1 ? existing[0] : existing;
        return JSON.stringify(finalValue);
    }, []);

    const applyBulkUpdates = async () => {
        const updates = [];
        try {
            posts.forEach((post) => {
                const update = bulkUpdates[post.post_id];
                if (!update) return;

                const payload = { post_id: post.post_id };
                if (update.seo_title) payload.seo_title = update.seo_title;
                if (update.seo_description) payload.seo_description = update.seo_description;
                if (update.og_title) payload.og_title = update.og_title;
                if (update.og_description) payload.og_description = update.og_description;
                if (update.schema_json) {
                    payload.schema_json = schemaMode === 'append'
                        ? mergeSchema(post.schema_json, update.schema_json)
                        : update.schema_json;
                }
                if (Object.keys(payload).length > 1) {
                    updates.push(payload);
                }
            });
        } catch (err) {
            setError(err.message || 'Schema merge failed');
            return;
        }

        if (updates.length === 0) {
            setError(__('No updates to apply.', 'wp-mcp-connect'));
            return;
        }

        setBulkSaving(true);
        setError(null);
        try {
            await apiFetch({ path: '/mcp/v1/seo/bulk-update', method: 'POST', data: { updates } });
            setBulkUpdates({});
            loadAuditData();
        } catch (err) {
            setError(err.message || 'Failed to apply bulk updates');
        } finally {
            setBulkSaving(false);
        }
    };

    const getMissingBadges = useCallback((post) => {
        const badges = [];
        if (post.missing_fields?.includes('seo_title')) {
            badges.push(<Badge key="title" variant="warning">Missing Title</Badge>);
        }
        if (post.missing_fields?.includes('seo_description')) {
            badges.push(<Badge key="desc" variant="warning">Missing Description</Badge>);
        }
        if (post.missing_fields?.includes('og_title')) {
            badges.push(<Badge key="og-title" variant="info">Missing OG Title</Badge>);
        }
        if (post.missing_fields?.includes('og_description')) {
            badges.push(<Badge key="og-desc" variant="info">Missing OG Desc</Badge>);
        }
        if (post.missing_fields?.includes('schema_json')) {
            badges.push(<Badge key="schema" variant="neutral">Missing Schema</Badge>);
        }
        return badges;
    }, []);

    const toggleRowEdit = (postId) => {
        setExpandedRows((prev) => {
            const next = new Set(prev);
            if (next.has(postId)) {
                next.delete(postId);
            } else {
                next.add(postId);
            }
            return next;
        });
    };

    const handleSuggest = async (postId) => {
        setSuggesting((prev) => ({ ...prev, [postId]: true }));
        try {
            const res = await apiFetch({ path: '/mcp/v1/seo/suggest', method: 'POST', data: { post_ids: [postId] } });
            const suggestion = res.suggestions?.[0];
            if (suggestion) {
                setBulkUpdates((prev) => ({
                    ...prev,
                    [postId]: {
                        ...prev[postId],
                        seo_title: suggestion.seo_title || prev[postId]?.seo_title || '',
                        seo_description: suggestion.seo_description || prev[postId]?.seo_description || '',
                    },
                }));
                setExpandedRows((prev) => new Set(prev).add(postId));
            }
        } catch (err) {
            setError(err.message || 'Failed to generate suggestions');
        } finally {
            setSuggesting((prev) => ({ ...prev, [postId]: false }));
        }
    };

    return (
        <div className="seo-audit-content fade-in">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            {summary && (
                <div className="grid grid-cols-4 grid-auto">
                    <MetricCard
                        label={__('Missing SEO Title', 'wp-mcp-connect')}
                        value={summary.missing_title || 0}
                        className={summary.missing_title > 0 ? 'stat-warning' : 'stat-success'}
                    />
                    <MetricCard
                        label={__('Missing Meta Description', 'wp-mcp-connect')}
                        value={summary.missing_description || 0}
                        className={summary.missing_description > 0 ? 'stat-warning' : 'stat-success'}
                    />
                    <MetricCard
                        label={__('Missing OG Title', 'wp-mcp-connect')}
                        value={summary.missing_og_title || 0}
                        className={summary.missing_og_title > 0 ? 'stat-warning' : 'stat-success'}
                    />
                    <MetricCard
                        label={__('Missing Schema', 'wp-mcp-connect')}
                        value={summary.missing_schema || 0}
                        className={summary.missing_schema > 0 ? 'stat-warning' : 'stat-success'}
                    />
                </div>
            )}

            <Card>
                <div className="bulk-edit-controls">
                    {Object.keys(bulkUpdates).length > 0 && (
                        <>
                            <SelectControl
                                label={__('Schema Mode', 'wp-mcp-connect')}
                                value={schemaMode}
                                onChange={setSchemaMode}
                                options={[
                                    { value: 'replace', label: __('Replace', 'wp-mcp-connect') },
                                    { value: 'append', label: __('Append', 'wp-mcp-connect') },
                                ]}
                            />
                            <Button variant="primary" onClick={applyBulkUpdates} disabled={bulkSaving}>
                                {bulkSaving
                                    ? __('Saving...', 'wp-mcp-connect')
                                    : __('Save Changes', 'wp-mcp-connect') + ` (${Object.keys(bulkUpdates).length})`}
                            </Button>
                        </>
                    )}
                </div>
                <div className="mcp-filter-row">
                    <SelectControl
                        label={__('Post Type', 'wp-mcp-connect')}
                        value={postType}
                        onChange={(value) => { setPostType(value); setPage(1); }}
                        options={postTypes}
                    />
                    <SelectControl
                        label={__('Missing Field', 'wp-mcp-connect')}
                        value={missingField}
                        onChange={(value) => { setMissingField(value); setPage(1); }}
                        options={[
                            { value: '', label: __('All', 'wp-mcp-connect') },
                            { value: 'seo_title', label: __('SEO Title', 'wp-mcp-connect') },
                            { value: 'seo_description', label: __('Meta Description', 'wp-mcp-connect') },
                            { value: 'og_title', label: __('OG Title', 'wp-mcp-connect') },
                            { value: 'og_description', label: __('OG Description', 'wp-mcp-connect') },
                            { value: 'schema_json', label: __('Schema JSON', 'wp-mcp-connect') },
                        ]}
                    />
                </div>

                {loading ? (
                    <div className="mcp-loading">
                        <SkeletonLoader type="table" rows={5} />
                    </div>
                ) : posts.length > 0 ? (
                    <>
                        <table className="mcp-table">
                            <thead>
                            <tr>
                                <th>{__('Title', 'wp-mcp-connect')}</th>
                                <th>{__('Type', 'wp-mcp-connect')}</th>
                                <th>{__('Missing Fields', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {posts.map((post) => (
                                <React.Fragment key={post.post_id}>
                                    <tr>
                                        <td>{post.post_title}</td>
                                        <td>{post.post_type}</td>
                                        <td>
                                            <div className="mcp-badge-group">
                                                {getMissingBadges(post)}
                                            </div>
                                        </td>
                                        <td className="actions">
                                            <Button
                                                variant="tertiary"
                                                onClick={() => toggleRowEdit(post.post_id)}
                                                className={expandedRows.has(post.post_id) ? 'is-active' : ''}
                                            >
                                                {expandedRows.has(post.post_id)
                                                    ? __('Close', 'wp-mcp-connect')
                                                    : __('Edit', 'wp-mcp-connect')}
                                            </Button>
                                            <Button
                                                variant="tertiary"
                                                onClick={() => handleSuggest(post.post_id)}
                                                disabled={suggesting[post.post_id]}
                                            >
                                                {suggesting[post.post_id]
                                                    ? __('Suggesting...', 'wp-mcp-connect')
                                                    : __('Suggest', 'wp-mcp-connect')}
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                icon={external}
                                                href={post.edit_url}
                                                target="_blank"
                                            >
                                                {__('WP Edit', 'wp-mcp-connect')}
                                            </Button>
                                        </td>
                                    </tr>
                                    {expandedRows.has(post.post_id) && (
                                        <tr className="inline-edit-row">
                                            <td colSpan={4}>
                                                <div className="inline-edit-panel">
                                                    <div className="inline-edit-fields">
                                                        <TextControl
                                                            label={__('SEO Title', 'wp-mcp-connect')}
                                                            value={bulkUpdates[post.post_id]?.seo_title || ''}
                                                            onChange={(value) => updateBulkField(post.post_id, 'seo_title', value)}
                                                            placeholder={post.seo_title || __('Enter SEO title...', 'wp-mcp-connect')}
                                                        />
                                                        <TextControl
                                                            label={__('Meta Description', 'wp-mcp-connect')}
                                                            value={bulkUpdates[post.post_id]?.seo_description || ''}
                                                            onChange={(value) => updateBulkField(post.post_id, 'seo_description', value)}
                                                            placeholder={post.seo_description || __('Enter meta description...', 'wp-mcp-connect')}
                                                        />
                                                        <TextControl
                                                            label={__('OG Title', 'wp-mcp-connect')}
                                                            value={bulkUpdates[post.post_id]?.og_title || ''}
                                                            onChange={(value) => updateBulkField(post.post_id, 'og_title', value)}
                                                        />
                                                        <TextControl
                                                            label={__('OG Description', 'wp-mcp-connect')}
                                                            value={bulkUpdates[post.post_id]?.og_description || ''}
                                                            onChange={(value) => updateBulkField(post.post_id, 'og_description', value)}
                                                        />
                                                        <TextControl
                                                            label={__('Schema JSON', 'wp-mcp-connect')}
                                                            value={bulkUpdates[post.post_id]?.schema_json || ''}
                                                            onChange={(value) => updateBulkField(post.post_id, 'schema_json', value)}
                                                        />
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                </React.Fragment>
                            ))}
                            </tbody>
                        </table>

                        {totalPages > 1 && (
                            <div className="mcp-pagination">
                                <Button
                                    variant="secondary"
                                    disabled={page <= 1}
                                    onClick={() => setPage(page - 1)}
                                >
                                    {__('Previous', 'wp-mcp-connect')}
                                </Button>
                                <span className="page-info">
                                    {__('Page', 'wp-mcp-connect')} {page} {__('of', 'wp-mcp-connect')} {totalPages}
                                </span>
                                <Button
                                    variant="secondary"
                                    disabled={page >= totalPages}
                                    onClick={() => setPage(page + 1)}
                                >
                                    {__('Next', 'wp-mcp-connect')}
                                </Button>
                            </div>
                        )}
                    </>
                ) : (
                    <EmptyState
                        message={__('No posts with missing SEO data found. Great job!', 'wp-mcp-connect')}
                    />
                )}
            </Card>
        </div>
    );
};

export default SEOAudit;
