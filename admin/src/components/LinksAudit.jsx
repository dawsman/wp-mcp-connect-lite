import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice, Modal, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const LinksAudit = () => {
    const [data, setData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [redirectModal, setRedirectModal] = useState(null);
    const [redirectTo, setRedirectTo] = useState('');

    useEffect(() => {
        loadBrokenLinks();
    }, []);

    const loadBrokenLinks = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await apiFetch({ path: '/mcp/v1/content/broken-links?post_type=any&per_page=50' });
            setData(response.posts || []);
        } catch (err) {
            setError(err.message || 'Failed to load broken links');
        } finally {
            setLoading(false);
        }
    };

    const normalizeFromUrl = (url) => {
        try {
            const parsed = new URL(url, window.location.origin);
            return parsed.pathname || '/';
        } catch {
            return url;
        }
    };

    const openRedirectModal = (url) => {
        setRedirectModal(normalizeFromUrl(url));
        setRedirectTo('');
    };

    const createRedirect = async () => {
        if (!redirectModal || !redirectTo) return;
        try {
            await apiFetch({
                path: '/wp/v2/redirects',
                method: 'POST',
                data: {
                    title: `Redirect: ${redirectModal}`,
                    status: 'publish',
                    from_url: redirectModal,
                    to_url: redirectTo,
                    status_code: 301,
                    enabled: true,
                },
            });
            setRedirectModal(null);
            setRedirectTo('');
        } catch (err) {
            setError(err.message || 'Failed to create redirect');
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
        <div className="links-audit-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                {data.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('Post', 'wp-mcp-connect')}</th>
                                <th>{__('Broken Links', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((post) => (
                                <tr key={post.post_id}>
                                    <td>
                                        <a href={post.edit_url} target="_blank" rel="noreferrer">
                                            {post.post_title}
                                        </a>
                                    </td>
                                    <td>
                                        <ul className="flat-list">
                                            {post.broken_links.map((link, idx) => (
                                                <li key={idx}>
                                                    <code>{link.url}</code> ({link.reason})
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="actions">
                                        {post.broken_links.slice(0, 1).map((link, idx) => (
                                            <Button
                                                key={idx}
                                                variant="secondary"
                                                onClick={() => openRedirectModal(link.url)}
                                            >
                                                {__('Create Redirect', 'wp-mcp-connect')}
                                            </Button>
                                        ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No broken links found.', 'wp-mcp-connect')}</p>
                )}
            </div>

            {redirectModal && (
                <Modal
                    title={__('Create Redirect', 'wp-mcp-connect')}
                    onRequestClose={() => setRedirectModal(null)}
                >
                    <TextControl
                        label={__('From URL', 'wp-mcp-connect')}
                        value={redirectModal}
                        disabled
                    />
                    <TextControl
                        label={__('To URL', 'wp-mcp-connect')}
                        value={redirectTo}
                        onChange={setRedirectTo}
                        placeholder="/new-target"
                    />
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px', marginTop: '20px' }}>
                        <Button variant="secondary" onClick={() => setRedirectModal(null)}>
                            {__('Cancel', 'wp-mcp-connect')}
                        </Button>
                        <Button variant="primary" onClick={createRedirect} disabled={!redirectTo}>
                            {__('Create Redirect', 'wp-mcp-connect')}
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
};

export default LinksAudit;
