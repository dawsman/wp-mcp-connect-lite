import { useState, useEffect } from '@wordpress/element';
import { Spinner, Notice, SelectControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const TasksQueue = () => {
    const [tasks, setTasks] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [statusFilter, setStatusFilter] = useState('');
    const [selected, setSelected] = useState({});

    useEffect(() => {
        loadTasks();
    }, [statusFilter]);

    const loadTasks = async () => {
        setLoading(true);
        setError(null);
        try {
            const path = statusFilter ? `/mcp/v1/tasks?status=${statusFilter}&per_page=50` : '/mcp/v1/tasks?per_page=50';
            const response = await apiFetch({ path });
            setTasks(response.tasks || []);
        } catch (err) {
            setError(err.message || 'Failed to load tasks');
        } finally {
            setLoading(false);
        }
    };

    const toggleSelect = (id) => {
        setSelected((prev) => ({ ...prev, [id]: !prev[id] }));
    };

    const bulkResolve = async () => {
        const ids = Object.keys(selected).filter((id) => selected[id]).map((id) => parseInt(id, 10));
        if (!ids.length) return;
        try {
            await apiFetch({ path: '/mcp/v1/tasks/bulk', method: 'POST', data: { ids, action: 'resolve' } });
            setSelected({});
            loadTasks();
        } catch (err) {
            setError(err.message || 'Failed to resolve tasks');
        }
    };

    const exportTasks = async () => {
        try {
            const response = await apiFetch({ path: '/mcp/v1/tasks/export' });
            const blob = new Blob([response.csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = response.filename || 'tasks.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        } catch (err) {
            setError(err.message || 'Failed to export tasks');
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
        <div className="tasks-queue">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="wp-mcp-connect-card">
                <div className="task-actions">
                    <SelectControl
                        label={__('Status', 'wp-mcp-connect')}
                        value={statusFilter}
                        onChange={setStatusFilter}
                        options={[
                            { value: '', label: __('All', 'wp-mcp-connect') },
                            { value: 'open', label: __('Open', 'wp-mcp-connect') },
                            { value: 'resolved', label: __('Resolved', 'wp-mcp-connect') },
                        ]}
                    />
                    <div className="task-actions-buttons">
                        <Button variant="secondary" onClick={exportTasks}>
                            {__('Export CSV', 'wp-mcp-connect')}
                        </Button>
                        <Button variant="primary" onClick={bulkResolve}>
                            {__('Resolve Selected', 'wp-mcp-connect')}
                        </Button>
                    </div>
                </div>

                {tasks.length > 0 ? (
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>{__('Task', 'wp-mcp-connect')}</th>
                                <th>{__('Type', 'wp-mcp-connect')}</th>
                                <th>{__('Status', 'wp-mcp-connect')}</th>
                                <th>{__('Priority', 'wp-mcp-connect')}</th>
                                <th>{__('Updated', 'wp-mcp-connect')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tasks.map((task) => (
                                <tr key={task.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            checked={!!selected[task.id]}
                                            onChange={() => toggleSelect(task.id)}
                                        />
                                    </td>
                                    <td>{task.title}</td>
                                    <td>{task.type}</td>
                                    <td>{task.status}</td>
                                    <td>{task.priority}</td>
                                    <td>{task.updated_at}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                ) : (
                    <p className="wp-mcp-connect-empty">{__('No tasks found.', 'wp-mcp-connect')}</p>
                )}
            </div>
        </div>
    );
};

export default TasksQueue;
