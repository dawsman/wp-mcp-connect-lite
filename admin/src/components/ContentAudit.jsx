import { useEffect, useState } from '@wordpress/element';
import { Spinner, Notice, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, SkeletonLoader, EmptyState } from './ui';

const ContentAudit = () => {
    const [brokenImages, setBrokenImages] = useState([]);
    const [orphaned, setOrphaned] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [suggestions, setSuggestions] = useState({});

    const [thinContent, setThinContent] = useState([]);
    const [thinLoading, setThinLoading] = useState(true);
    const [thinError, setThinError] = useState(null);

    const [duplicates, setDuplicates] = useState([]);
    const [dupLoading, setDupLoading] = useState(true);
    const [dupError, setDupError] = useState(null);

    useEffect(() => {
        loadContentAudits();
    }, []);

    useEffect(() => {
        apiFetch({ path: '/mcp/v1/content/thin?threshold=300&per_page=50' })
            .then((res) => setThinContent(res.results || []))
            .catch((err) => setThinError(err.message || 'Failed to load thin content'))
            .finally(() => setThinLoading(false));
    }, []);

    useEffect(() => {
        apiFetch({ path: '/mcp/v1/content/duplicates?threshold=0.6' })
            .then((res) => setDuplicates(res.pairs || []))
            .catch((err) => setDupError(err.message || 'Failed to load duplicate content'))
            .finally(() => setDupLoading(false));
    }, []);

    const loadContentAudits = async () => {
        setLoading(true);
        setError(null);
        try {
            const [brokenResp, orphanResp] = await Promise.all([
                apiFetch({ path: '/mcp/v1/content/broken-images?post_type=any&per_page=50' }),
                apiFetch({ path: '/mcp/v1/content/orphaned?post_type=post&per_page=50' }),
            ]);
            setBrokenImages(brokenResp.results || []);
            setOrphaned(orphanResp.posts || []);
        } catch (err) {
            setError(err.message || 'Failed to load content audits');
        } finally {
            setLoading(false);
        }
    };

    const loadSuggestions = async (postId) => {
        try {
            const response = await apiFetch({ path: `/mcp/v1/links/suggestions?post_id=${postId}` });
            setSuggestions((prev) => ({ ...prev, [postId]: response.suggestions || [] }));
        } catch (err) {
            setError(err.message || 'Failed to load suggestions');
        }
    };

    if (loading) {
        return (
            <div className="wp-mcp-connect-loading">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="content-audit-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                <h2>{__('Broken Images', 'wp-mcp-connect')}</h2>
                {brokenImages.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('Post', 'wp-mcp-connect')}</th>
                                <th>{__('Issues', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {brokenImages.map((post) => (
                                <tr key={post.post_id}>
                                    <td>
                                        <a href={post.edit_url} target="_blank" rel="noreferrer">
                                            {post.post_title}
                                        </a>
                                    </td>
                                    <td>
                                        <ul className="flat-list">
                                            {post.broken_images.map((img, idx) => (
                                                <li key={idx}>
                                                    {img.url ? <code>{img.url}</code> : __('Attachment', 'wp-mcp-connect')}
                                                    {' '}({img.issue})
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No broken images found.', 'wp-mcp-connect')}</p>
                )}
            </div>

            <div className="wp-mcp-connect-card">
                <h2>{__('Orphaned Content', 'wp-mcp-connect')}</h2>
                {orphaned.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('Post', 'wp-mcp-connect')}</th>
                                <th>{__('Suggestions', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orphaned.map((post) => (
                                <tr key={post.post_id}>
                                    <td>
                                        <a href={post.edit_url} target="_blank" rel="noreferrer">
                                            {post.post_title}
                                        </a>
                                    </td>
                                    <td>
                                        {suggestions[post.post_id] ? (
                                            <ul className="flat-list">
                                                {suggestions[post.post_id].map((suggestion) => (
                                                    <li key={suggestion.id}>
                                                        <a href={suggestion.url} target="_blank" rel="noreferrer">
                                                            {suggestion.title}
                                                        </a>
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <span className="muted">{__('Not loaded', 'wp-mcp-connect')}</span>
                                        )}
                                    </td>
                                    <td>
                                        <Button variant="secondary" onClick={() => loadSuggestions(post.post_id)}>
                                            {__('Load Suggestions', 'wp-mcp-connect')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No orphaned content found.', 'wp-mcp-connect')}</p>
                )}
            </div>

            <Card title={__('Thin Content', 'wp-mcp-connect')}>
                {thinLoading ? (
                    <SkeletonLoader type="table" rows={3} />
                ) : thinError ? (
                    <Notice status="error" isDismissible={false}>{thinError}</Notice>
                ) : thinContent.length > 0 ? (
                    <table className="mcp-table">
                        <thead>
                            <tr>
                                <th>{__('Post', 'wp-mcp-connect')}</th>
                                <th>{__('Word Count', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {thinContent.map((post) => (
                                <tr key={post.post_id}>
                                    <td>
                                        <a href={post.edit_url} target="_blank" rel="noreferrer">
                                            {post.post_title}
                                        </a>
                                    </td>
                                    <td>{post.word_count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <EmptyState message={__('No thin content found (all posts ≥ 300 words).', 'wp-mcp-connect')} />
                )}
            </Card>

            <Card title={__('Duplicate Content', 'wp-mcp-connect')}>
                {dupLoading ? (
                    <SkeletonLoader type="table" rows={3} />
                ) : dupError ? (
                    <Notice status="error" isDismissible={false}>{dupError}</Notice>
                ) : duplicates.length > 0 ? (
                    <table className="mcp-table">
                        <thead>
                            <tr>
                                <th>{__('Post A', 'wp-mcp-connect')}</th>
                                <th>{__('Post B', 'wp-mcp-connect')}</th>
                                <th>{__('Similarity', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {duplicates.map((pair, i) => (
                                <tr key={i}>
                                    <td>
                                        <a href={pair.post_a.edit_url} target="_blank" rel="noreferrer">
                                            {pair.post_a.post_title}
                                        </a>
                                    </td>
                                    <td>
                                        <a href={pair.post_b.edit_url} target="_blank" rel="noreferrer">
                                            {pair.post_b.post_title}
                                        </a>
                                    </td>
                                    <td>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                            <div style={{ flex: 1, height: 6, background: '#e5e7eb', borderRadius: 3 }}>
                                                <div style={{ width: `${Math.round(pair.similarity * 100)}%`, height: '100%', background: pair.similarity >= 0.8 ? '#ef4444' : '#f59e0b', borderRadius: 3 }} />
                                            </div>
                                            <span style={{ whiteSpace: 'nowrap' }}>{Math.round(pair.similarity * 100)}%</span>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <EmptyState message={__('No duplicate content found above 60% similarity.', 'wp-mcp-connect')} />
                )}
            </Card>
        </div>
    );
};

export default ContentAudit;
