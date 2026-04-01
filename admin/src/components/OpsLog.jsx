import { useEffect, useState } from '@wordpress/element';
import { Spinner, Notice, Button, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const OpsLog = () => {
    const [ops, setOps] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [opType, setOpType] = useState('');

    useEffect(() => {
        loadOps();
    }, [opType]);

    const loadOps = async () => {
        setLoading(true);
        setError(null);
        try {
            const path = opType ? `/mcp/v1/ops?op_type=${opType}&per_page=50` : '/mcp/v1/ops?per_page=50';
            const response = await apiFetch({ path });
            setOps(response.ops || []);
        } catch (err) {
            setError(err.message || 'Failed to load operations');
        } finally {
            setLoading(false);
        }
    };

    const rollback = async (id) => {
        if (!window.confirm(__('Rollback this operation?', 'wp-mcp-connect'))) return;
        try {
            await apiFetch({ path: '/mcp/v1/ops/rollback', method: 'POST', data: { id } });
            loadOps();
        } catch (err) {
            setError(err.message || 'Rollback failed');
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
        <div className="ops-log">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}
            <div className="wp-mcp-connect-card">
                <SelectControl
                    label={__('Operation Type', 'wp-mcp-connect')}
                    value={opType}
                    onChange={setOpType}
                    options={[
                        { value: '', label: __('All', 'wp-mcp-connect') },
                        { value: 'seo_bulk', label: __('SEO Bulk Update', 'wp-mcp-connect') },
                        { value: 'redirects_import', label: __('Redirect Import', 'wp-mcp-connect') },
                        { value: 'custom_css', label: __('Custom CSS', 'wp-mcp-connect') },
                    ]}
                />

                {ops.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('ID', 'wp-mcp-connect')}</th>
                                <th>{__('Type', 'wp-mcp-connect')}</th>
                                <th>{__('Status', 'wp-mcp-connect')}</th>
                                <th>{__('User', 'wp-mcp-connect')}</th>
                                <th>{__('Created', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {ops.map((op) => (
                                <tr key={op.id}>
                                    <td>{op.id}</td>
                                    <td>{op.op_type}</td>
                                    <td>{op.status}</td>
                                    <td>{op.user_id}</td>
                                    <td>{op.created_at}</td>
                                    <td className="actions">
                                        <Button variant="secondary" onClick={() => rollback(op.id)}>
                                            {__('Rollback', 'wp-mcp-connect')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No operations logged yet.', 'wp-mcp-connect')}</p>
                )}
            </div>
        </div>
    );
};

export default OpsLog;
