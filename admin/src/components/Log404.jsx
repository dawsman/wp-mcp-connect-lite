import { useState, useEffect } from '@wordpress/element';
import { Spinner, Notice, SelectControl, Button, Modal, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const Log404 = () => {
    const [entries, setEntries] = useState([]);
    const [status, setStatus] = useState('open');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [redirectEntry, setRedirectEntry] = useState(null);
    const [redirectTo, setRedirectTo] = useState('');

    useEffect(() => {
        loadEntries();
    }, [status]);

    const loadEntries = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await apiFetch({ path: `/mcp/v1/404?status=${status}&per_page=50` });
            setEntries(response.entries || []);
        } catch (err) {
            setError(err.message || 'Failed to load 404 log');
        } finally {
            setLoading(false);
        }
    };

    const updateStatus = async (id, newStatus) => {
        try {
            await apiFetch({ path: '/mcp/v1/404', method: 'PATCH', data: { id, status: newStatus } });
            loadEntries();
        } catch (err) {
            setError(err.message || 'Failed to update 404 status');
        }
    };

    const createRedirect = async () => {
        if (!redirectEntry || !redirectTo) return;
        try {
            await apiFetch({
                path: '/mcp/v1/404/redirect',
                method: 'POST',
                data: { id: redirectEntry.id, to_url: redirectTo, status_code: 301 },
            });
            setRedirectEntry(null);
            setRedirectTo('');
            loadEntries();
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
        <div className="log-404-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                <SelectControl
                    label={__('Status', 'wp-mcp-connect')}
                    value={status}
                    onChange={setStatus}
                    options={[
                        { value: 'open', label: __('Open', 'wp-mcp-connect') },
                        { value: 'ignored', label: __('Ignored', 'wp-mcp-connect') },
                        { value: 'resolved', label: __('Resolved', 'wp-mcp-connect') },
                        { value: 'all', label: __('All', 'wp-mcp-connect') },
                    ]}
                />

                {entries.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('URL', 'wp-mcp-connect')}</th>
                                <th>{__('Hits', 'wp-mcp-connect')}</th>
                                <th>{__('Last Seen', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {entries.map((entry) => (
                                <tr key={entry.id}>
                                    <td><code>{entry.url}</code></td>
                                    <td>{entry.hits}</td>
                                    <td>{entry.last_seen}</td>
                                    <td className="actions">
                                        <Button variant="secondary" onClick={() => setRedirectEntry(entry)}>
                                            {__('Create Redirect', 'wp-mcp-connect')}
                                        </Button>
                                        <Button variant="tertiary" onClick={() => updateStatus(entry.id, 'ignored')}>
                                            {__('Ignore', 'wp-mcp-connect')}
                                        </Button>
                                        <Button variant="tertiary" onClick={() => updateStatus(entry.id, 'resolved')}>
                                            {__('Resolve', 'wp-mcp-connect')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No 404s logged.', 'wp-mcp-connect')}</p>
                )}
            </div>

            {redirectEntry && (
                <Modal title={__('Create Redirect', 'wp-mcp-connect')} onRequestClose={() => setRedirectEntry(null)}>
                    <TextControl label={__('From URL', 'wp-mcp-connect')} value={redirectEntry.url} disabled />
                    <TextControl
                        label={__('To URL', 'wp-mcp-connect')}
                        value={redirectTo}
                        onChange={setRedirectTo}
                        placeholder="/new-target"
                    />
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px', marginTop: '20px' }}>
                        <Button variant="secondary" onClick={() => setRedirectEntry(null)}>
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

export default Log404;
