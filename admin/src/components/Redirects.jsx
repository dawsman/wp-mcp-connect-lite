import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, TextControl, SelectControl, Modal, Notice, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { plus, trash, upload, download } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const Redirects = () => {
    const [redirects, setRedirects] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [showAddModal, setShowAddModal] = useState(false);
    const [showImportModal, setShowImportModal] = useState(false);
    const [newRedirect, setNewRedirect] = useState({ from_url: '', to_url: '', status_code: '301', enabled: true });
    const [importData, setImportData] = useState('');
    const [importMode, setImportMode] = useState('merge');

    useEffect(() => {
        loadRedirects();
    }, []);

    const loadRedirects = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await apiFetch({ path: '/wp/v2/redirects?per_page=100' });
            setRedirects(data || []);
        } catch (err) {
            setError(err.message || 'Failed to load redirects');
        } finally {
            setLoading(false);
        }
    };

    const handleAddRedirect = async () => {
        try {
            await apiFetch({
                path: '/wp/v2/redirects',
                method: 'POST',
                data: {
                    title: `Redirect: ${newRedirect.from_url}`,
                    status: 'publish',
                    from_url: newRedirect.from_url,
                    to_url: newRedirect.to_url,
                    status_code: newRedirect.status_code,
                    enabled: newRedirect.enabled,
                },
            });
            setShowAddModal(false);
            setNewRedirect({ from_url: '', to_url: '', status_code: '301', enabled: true });
            loadRedirects();
        } catch (err) {
            setError(err.message || 'Failed to create redirect');
        }
    };

    const handleDeleteRedirect = async (id) => {
        if (!window.confirm(__('Are you sure you want to delete this redirect?', 'wp-mcp-connect'))) {
            return;
        }

        try {
            await apiFetch({
                path: `/wp/v2/redirects/${id}?force=true`,
                method: 'DELETE',
            });
            loadRedirects();
        } catch (err) {
            setError(err.message || 'Failed to delete redirect');
        }
    };

    const handleToggleEnabled = async (redirect) => {
        try {
            await apiFetch({
                path: `/wp/v2/redirects/${redirect.id}`,
                method: 'POST',
                data: {
                    enabled: !(redirect.enabled ?? redirect.meta?.enabled ?? true),
                },
            });
            loadRedirects();
        } catch (err) {
            setError(err.message || 'Failed to update redirect');
        }
    };

    const handleExport = async () => {
        try {
            const data = await apiFetch({ path: '/mcp/v1/redirects/export' });
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'redirects-export.json';
            a.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            setError(err.message || 'Failed to export redirects');
        }
    };

    const handleImport = async () => {
        try {
            const parsed = JSON.parse(importData);
            await apiFetch({
                path: '/mcp/v1/redirects/import',
                method: 'POST',
                data: {
                    redirects: parsed,
                    mode: importMode,
                },
            });
            setShowImportModal(false);
            setImportData('');
            loadRedirects();
        } catch (err) {
            setError(err.message || 'Failed to import redirects. Please check the JSON format.');
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
        <div className="redirects-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '15px' }}>
                    <h2 style={{ margin: 0 }}>{__('Redirect Rules', 'wp-mcp-connect')}</h2>
                    <div style={{ display: 'flex', gap: '10px' }}>
                        <Button
                            variant="secondary"
                            icon={download}
                            onClick={handleExport}
                        >
                            {__('Export', 'wp-mcp-connect')}
                        </Button>
                        <Button
                            variant="secondary"
                            icon={upload}
                            onClick={() => setShowImportModal(true)}
                        >
                            {__('Import', 'wp-mcp-connect')}
                        </Button>
                        <Button
                            variant="primary"
                            icon={plus}
                            onClick={() => setShowAddModal(true)}
                        >
                            {__('Add Redirect', 'wp-mcp-connect')}
                        </Button>
                    </div>
                </div>

                {redirects.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('From URL', 'wp-mcp-connect')}</th>
                                <th>{__('To URL', 'wp-mcp-connect')}</th>
                                <th>{__('Status', 'wp-mcp-connect')}</th>
                                <th>{__('Enabled', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {redirects.map((redirect) => (
                                <tr key={redirect.id}>
                                    <td><code>{redirect.from_url || redirect.meta?.from_url}</code></td>
                                    <td><code>{redirect.to_url || redirect.meta?.to_url}</code></td>
                                    <td>{redirect.status_code || redirect.meta?.status_code || '301'}</td>
                                    <td>
                                        <ToggleControl
                                            checked={(redirect.enabled ?? redirect.meta?.enabled ?? true) === true || (redirect.enabled ?? redirect.meta?.enabled) === 1}
                                            onChange={() => handleToggleEnabled(redirect)}
                                        />
                                    </td>
                                    <td className="actions">
                                        <Button
                                            variant="tertiary"
                                            icon={trash}
                                            isDestructive
                                            onClick={() => handleDeleteRedirect(redirect.id)}
                                        >
                                            {__('Delete', 'wp-mcp-connect')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">
                        {__('No redirects configured yet.', 'wp-mcp-connect')}
                    </p>
                )}
            </div>

            {showAddModal && (
                <Modal
                    title={__('Add Redirect', 'wp-mcp-connect')}
                    onRequestClose={() => setShowAddModal(false)}
                >
                    <div className="wp-mcp-connect-form">
                        <div className="form-row">
                            <TextControl
                                label={__('From URL', 'wp-mcp-connect')}
                                value={newRedirect.from_url}
                                onChange={(value) => setNewRedirect({ ...newRedirect, from_url: value })}
                                placeholder="/old-page"
                                help={__('Relative path to redirect from', 'wp-mcp-connect')}
                            />
                        </div>
                        <div className="form-row">
                            <TextControl
                                label={__('To URL', 'wp-mcp-connect')}
                                value={newRedirect.to_url}
                                onChange={(value) => setNewRedirect({ ...newRedirect, to_url: value })}
                                placeholder="/new-page or https://example.com/page"
                                help={__('Destination URL (relative or absolute)', 'wp-mcp-connect')}
                            />
                        </div>
                        <div className="form-row">
                            <SelectControl
                                label={__('Status Code', 'wp-mcp-connect')}
                                value={newRedirect.status_code}
                                onChange={(value) => setNewRedirect({ ...newRedirect, status_code: value })}
                                options={[
                                    { value: '301', label: '301 - Permanent Redirect' },
                                    { value: '302', label: '302 - Temporary Redirect' },
                                    { value: '307', label: '307 - Temporary Redirect (Strict)' },
                                    { value: '308', label: '308 - Permanent Redirect (Strict)' },
                                ]}
                            />
                        </div>
                        <div className="form-row">
                            <ToggleControl
                                label={__('Enabled', 'wp-mcp-connect')}
                                checked={newRedirect.enabled}
                                onChange={(value) => setNewRedirect({ ...newRedirect, enabled: value })}
                            />
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px', marginTop: '20px' }}>
                            <Button variant="secondary" onClick={() => setShowAddModal(false)}>
                                {__('Cancel', 'wp-mcp-connect')}
                            </Button>
                            <Button
                                variant="primary"
                                onClick={handleAddRedirect}
                                disabled={!newRedirect.from_url || !newRedirect.to_url}
                            >
                                {__('Add Redirect', 'wp-mcp-connect')}
                            </Button>
                        </div>
                    </div>
                </Modal>
            )}

            {showImportModal && (
                <Modal
                    title={__('Import Redirects', 'wp-mcp-connect')}
                    onRequestClose={() => setShowImportModal(false)}
                >
                    <div className="wp-mcp-connect-form">
                        <div className="form-row">
                            <label>{__('JSON Data', 'wp-mcp-connect')}</label>
                            <textarea
                                value={importData}
                                onChange={(e) => setImportData(e.target.value)}
                                placeholder='[{"from_url": "/old", "to_url": "/new", "status_code": 301}]'
                                rows={10}
                                style={{ width: '100%', fontFamily: 'monospace' }}
                            />
                        </div>
                        <div className="form-row">
                            <SelectControl
                                label={__('Import Mode', 'wp-mcp-connect')}
                                value={importMode}
                                onChange={setImportMode}
                                options={[
                                    { value: 'merge', label: __('Merge - Add new, skip existing', 'wp-mcp-connect') },
                                    { value: 'replace', label: __('Replace - Delete all existing first', 'wp-mcp-connect') },
                                ]}
                            />
                        </div>
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px', marginTop: '20px' }}>
                            <Button variant="secondary" onClick={() => setShowImportModal(false)}>
                                {__('Cancel', 'wp-mcp-connect')}
                            </Button>
                            <Button
                                variant="primary"
                                onClick={handleImport}
                                disabled={!importData}
                            >
                                {__('Import', 'wp-mcp-connect')}
                            </Button>
                        </div>
                    </div>
                </Modal>
            )}
        </div>
    );
};

export default Redirects;
