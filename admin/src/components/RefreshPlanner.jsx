import { useEffect, useState } from '@wordpress/element';
import { Spinner, Notice, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const RefreshPlanner = () => {
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        loadPlanner();
    }, []);

    const loadPlanner = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await apiFetch({ path: '/mcp/v1/gsc/refresh-planner?limit=50' });
            setItems(response.results || []);
        } catch (err) {
            setError(err.message || 'Failed to load refresh planner');
        } finally {
            setLoading(false);
        }
    };

    const createTask = async (item) => {
        try {
            await apiFetch({
                path: '/mcp/v1/tasks',
                method: 'POST',
                data: {
                    type: 'content_refresh',
                    post_id: item.post_id,
                    url: item.url,
                    source: 'gsc_refresh',
                    metadata: { reasons: item.reasons, metrics: item },
                },
            });
        } catch (err) {
            setError(err.message || 'Failed to create task');
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
        <div className="refresh-planner">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                {items.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('Page', 'wp-mcp-connect')}</th>
                                <th>{__('Reasons', 'wp-mcp-connect')}</th>
                                <th>{__('Impressions', 'wp-mcp-connect')}</th>
                                <th>{__('CTR', 'wp-mcp-connect')}</th>
                                <th>{__('Actions', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.id}>
                                    <td>
                                        {item.post_id ? (
                                            <a href={`/wp-admin/post.php?post=${item.post_id}&action=edit`} target="_blank" rel="noreferrer">
                                                {item.post_title || item.url}
                                            </a>
                                        ) : (
                                            <code>{item.url}</code>
                                        )}
                                    </td>
                                    <td>{item.reasons.join(', ')}</td>
                                    <td>{item.impressions}</td>
                                    <td>{(item.ctr * 100).toFixed(2)}%</td>
                                    <td>
                                        <Button variant="secondary" onClick={() => createTask(item)}>
                                            {__('Create Task', 'wp-mcp-connect')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No refresh opportunities found.', 'wp-mcp-connect')}</p>
                )}
            </div>
        </div>
    );
};

export default RefreshPlanner;
