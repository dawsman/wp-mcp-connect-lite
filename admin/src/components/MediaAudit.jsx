import { useEffect, useState } from '@wordpress/element';
import { TabPanel, Spinner, Notice, Button, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const MediaAudit = () => {
    const [missingAlt, setMissingAlt] = useState([]);
    const [oversized, setOversized] = useState([]);
    const [duplicates, setDuplicates] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [altEdits, setAltEdits] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        loadAll();
    }, []);

    const loadAll = async () => {
        setLoading(true);
        setError(null);
        try {
            const [missingAltResp, oversizedResp, duplicatesResp] = await Promise.all([
                apiFetch({ path: '/mcp/v1/media/missing-alt?per_page=50' }),
                apiFetch({ path: '/mcp/v1/media/oversized?per_page=50' }),
                apiFetch({ path: '/mcp/v1/media/duplicates?per_page=50' }),
            ]);
            setMissingAlt(missingAltResp.results || []);
            setOversized(oversizedResp.images || []);
            setDuplicates(duplicatesResp.duplicate_groups || []);
        } catch (err) {
            setError(err.message || 'Failed to load media audits');
        } finally {
            setLoading(false);
        }
    };

    const saveAltText = async () => {
        const updates = Object.entries(altEdits)
            .filter(([, alt]) => alt && alt.trim())
            .map(([id, alt_text]) => ({ id: parseInt(id, 10), alt_text }));

        if (updates.length === 0) {
            return;
        }

        setSaving(true);
        try {
            await apiFetch({ path: '/mcp/v1/media/bulk-alt-update', method: 'POST', data: { updates } });
            setAltEdits({});
            loadAll();
        } catch (err) {
            setError(err.message || 'Failed to update alt text');
        } finally {
            setSaving(false);
        }
    };

    const tabs = [
        { name: 'missing-alt', title: __('Missing Alt Text', 'wp-mcp-connect') },
        { name: 'oversized', title: __('Oversized Images', 'wp-mcp-connect') },
        { name: 'duplicates', title: __('Duplicate Images', 'wp-mcp-connect') },
    ];

    const renderMissingAlt = () => (
        <div className="wp-mcp-connect-card">
            <div className="media-actions">
                <Button variant="primary" onClick={saveAltText} disabled={saving}>
                    {saving ? __('Saving...', 'wp-mcp-connect') : __('Save Alt Text', 'wp-mcp-connect')}
                </Button>
            </div>
            {missingAlt.length > 0 ? (
                <table className="wp-mcp-connect-table">
                    <thead>
                        <tr>
                            <th>{__('Image', 'wp-mcp-connect')}</th>
                            <th>{__('Alt Text', 'wp-mcp-connect')}</th>
                            <th>{__('Resolution', 'wp-mcp-connect')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {missingAlt.map((img) => (
                            <tr key={img.id}>
                                <td>
                                    <a href={`/wp-admin/post.php?post=${img.id}&action=edit`} target="_blank" rel="noreferrer">
                                        {img.title || img.filename}
                                    </a>
                                </td>
                                <td>
                                    <TextControl
                                        value={altEdits[img.id] || ''}
                                        onChange={(value) => setAltEdits({ ...altEdits, [img.id]: value })}
                                        placeholder={__('Add alt text', 'wp-mcp-connect')}
                                    />
                                </td>
                                <td>{img.resolution}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <p className="wp-mcp-connect-empty">{__('No images missing alt text.', 'wp-mcp-connect')}</p>
            )}
        </div>
    );

    const renderOversized = () => (
        <div className="wp-mcp-connect-card">
            {oversized.length > 0 ? (
                <table className="wp-mcp-connect-table">
                    <thead>
                        <tr>
                            <th>{__('Image', 'wp-mcp-connect')}</th>
                            <th>{__('File Size', 'wp-mcp-connect')}</th>
                            <th>{__('Path', 'wp-mcp-connect')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {oversized.map((img) => (
                            <tr key={img.id}>
                                <td>
                                    <a href={`/wp-admin/post.php?post=${img.id}&action=edit`} target="_blank" rel="noreferrer">
                                        {img.title || img.file_path}
                                    </a>
                                </td>
                                <td>{(img.file_size / 1024 / 1024).toFixed(2)} MB</td>
                                <td><code>{img.file_path}</code></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <p className="wp-mcp-connect-empty">{__('No oversized images found.', 'wp-mcp-connect')}</p>
            )}
        </div>
    );

    const renderDuplicates = () => (
        <div className="wp-mcp-connect-card">
            {duplicates.length > 0 ? (
                <table className="wp-mcp-connect-table">
                    <thead>
                        <tr>
                            <th>{__('Duplicate Group', 'wp-mcp-connect')}</th>
                            <th>{__('Files', 'wp-mcp-connect')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {duplicates.map((dup, idx) => (
                            <tr key={idx}>
                                <td>{dup.hash}</td>
                                <td>
                                    <ul className="flat-list">
                                        {dup.images.map((item) => (
                                            <li key={item.id}>
                                                <a href={`/wp-admin/post.php?post=${item.id}&action=edit`} target="_blank" rel="noreferrer">
                                                    {item.title || item.file_path}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <p className="wp-mcp-connect-empty">{__('No duplicate images found.', 'wp-mcp-connect')}</p>
            )}
        </div>
    );

    if (loading) {
        return (
            <div className="wp-mcp-connect-loading">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="media-audit-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}
            <TabPanel className="wp-mcp-connect-sub-tabs" activeClass="is-active" tabs={tabs}>
                {(tab) => {
                    switch (tab.name) {
                        case 'missing-alt':
                            return renderMissingAlt();
                        case 'oversized':
                            return renderOversized();
                        case 'duplicates':
                            return renderDuplicates();
                        default:
                            return null;
                    }
                }}
            </TabPanel>
        </div>
    );
};

export default MediaAudit;
