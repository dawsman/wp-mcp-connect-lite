import { useState, useEffect } from '@wordpress/element';
import { Button, TextControl, SelectControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, Badge } from './ui';

const AutomationRules = () => {
    const [rules, setRules] = useState([]);
    const [conditions, setConditions] = useState({});
    const [actions, setActions] = useState({});
    const [loading, setLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);
    const [error, setError] = useState(null);
    const [formData, setFormData] = useState({
        name: '',
        condition: '',
        threshold: 0,
        action: '',
    });

    useEffect(() => {
        loadRules();
    }, []);

    const loadRules = async () => {
        setLoading(true);
        try {
            const data = await apiFetch({ path: '/mcp/v1/rules' });
            setRules(data.rules || []);
            setConditions(data.available_conditions || {});
            setActions(data.available_actions || {});
        } catch (err) {
            setError(err.message || 'Failed to load rules');
        } finally {
            setLoading(false);
        }
    };

    const createRule = async () => {
        if (!formData.name || !formData.condition || !formData.action) return;
        setError(null);
        try {
            await apiFetch({
                path: '/mcp/v1/rules',
                method: 'POST',
                data: formData,
            });
            setFormData({ name: '', condition: '', threshold: 0, action: '' });
            setShowForm(false);
            await loadRules();
        } catch (err) {
            setError(err.message || 'Failed to create rule');
        }
    };

    const toggleRule = async (id) => {
        try {
            const res = await apiFetch({
                path: `/mcp/v1/rules/${id}/toggle`,
                method: 'POST',
            });
            setRules(res.rules || []);
        } catch (err) {
            setError(err.message || 'Failed to toggle rule');
        }
    };

    const deleteRule = async (id) => {
        try {
            await apiFetch({
                path: `/mcp/v1/rules/${id}`,
                method: 'DELETE',
            });
            await loadRules();
        } catch (err) {
            setError(err.message || 'Failed to delete rule');
        }
    };

    const evaluateRules = async () => {
        try {
            const res = await apiFetch({
                path: '/mcp/v1/rules/evaluate',
                method: 'POST',
            });
            await loadRules();
        } catch (err) {
            setError(err.message || 'Failed to evaluate rules');
        }
    };

    const conditionOptions = [
        { value: '', label: __('Select condition...', 'wp-mcp-connect') },
        ...Object.entries(conditions).map(([key, label]) => ({ value: key, label })),
    ];

    const actionOptions = [
        { value: '', label: __('Select action...', 'wp-mcp-connect') },
        ...Object.entries(actions).map(([key, label]) => ({ value: key, label })),
    ];

    const timeAgo = (dateStr) => {
        if (!dateStr) return __('Never', 'wp-mcp-connect');
        try {
            const diff = Date.now() - new Date(dateStr).getTime();
            const mins = Math.floor(diff / 60000);
            if (mins < 1) return __('just now', 'wp-mcp-connect');
            if (mins < 60) return `${mins}m ago`;
            const hrs = Math.floor(mins / 60);
            if (hrs < 24) return `${hrs}h ago`;
            const days = Math.floor(hrs / 24);
            return `${days}d ago`;
        } catch {
            return dateStr;
        }
    };

    return (
        <Card
            title={__('Automation Rules', 'wp-mcp-connect')}
            actions={
                <div style={{ display: 'flex', gap: 8 }}>
                    <Button variant="secondary" size="small" onClick={evaluateRules} disabled={loading}>
                        {__('Run Now', 'wp-mcp-connect')}
                    </Button>
                    <Button variant="primary" size="small" onClick={() => setShowForm(!showForm)}>
                        {showForm ? __('Cancel', 'wp-mcp-connect') : __('Add Rule', 'wp-mcp-connect')}
                    </Button>
                </div>
            }
        >
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            {showForm && (
                <div className="mcp-form" style={{ marginBottom: 16, padding: 16, background: '#f9fafb', borderRadius: 8 }}>
                    <div className="form-row">
                        <TextControl
                            label={__('Rule Name', 'wp-mcp-connect')}
                            value={formData.name}
                            onChange={(value) => setFormData({ ...formData, name: value })}
                            placeholder={__('e.g., Alert on low health scores', 'wp-mcp-connect')}
                        />
                    </div>
                    <div className="form-row">
                        <SelectControl
                            label={__('Condition', 'wp-mcp-connect')}
                            value={formData.condition}
                            onChange={(value) => setFormData({ ...formData, condition: value })}
                            options={conditionOptions}
                        />
                    </div>
                    {formData.condition === 'health_score_below' && (
                        <div className="form-row">
                            <TextControl
                                label={__('Threshold', 'wp-mcp-connect')}
                                type="number"
                                value={formData.threshold}
                                onChange={(value) => setFormData({ ...formData, threshold: parseInt(value, 10) || 0 })}
                                help={__('Trigger when health score is below this value.', 'wp-mcp-connect')}
                            />
                        </div>
                    )}
                    <div className="form-row">
                        <SelectControl
                            label={__('Action', 'wp-mcp-connect')}
                            value={formData.action}
                            onChange={(value) => setFormData({ ...formData, action: value })}
                            options={actionOptions}
                        />
                    </div>
                    <Button variant="primary" onClick={createRule} disabled={!formData.name || !formData.condition || !formData.action}>
                        {__('Create Rule', 'wp-mcp-connect')}
                    </Button>
                </div>
            )}

            {loading && <p className="text-muted">{__('Loading rules...', 'wp-mcp-connect')}</p>}

            {!loading && rules.length === 0 && (
                <p className="text-muted">
                    {__('No automation rules configured. Add a rule to automate SEO monitoring.', 'wp-mcp-connect')}
                </p>
            )}

            {!loading && rules.length > 0 && (
                <table className="mcp-table">
                    <thead>
                        <tr>
                            <th>{__('Name', 'wp-mcp-connect')}</th>
                            <th>{__('Condition', 'wp-mcp-connect')}</th>
                            <th>{__('Action', 'wp-mcp-connect')}</th>
                            <th>{__('Last Run', 'wp-mcp-connect')}</th>
                            <th>{__('Runs', 'wp-mcp-connect')}</th>
                            <th className="actions">{__('Actions', 'wp-mcp-connect')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rules.map((rule) => (
                            <tr key={rule.id}>
                                <td>
                                    <span style={{ marginRight: 8 }}>{rule.name}</span>
                                    <Badge variant={rule.enabled ? 'success' : 'neutral'}>
                                        {rule.enabled ? __('Active', 'wp-mcp-connect') : __('Paused', 'wp-mcp-connect')}
                                    </Badge>
                                </td>
                                <td className="text-sm">{conditions[rule.condition] || rule.condition}
                                    {rule.threshold ? ` (< ${rule.threshold})` : ''}
                                </td>
                                <td className="text-sm">{actions[rule.action] || rule.action}</td>
                                <td className="text-sm muted">{timeAgo(rule.last_run)}</td>
                                <td className="text-sm">{rule.run_count || 0}</td>
                                <td className="actions">
                                    <Button
                                        variant="tertiary"
                                        size="small"
                                        onClick={() => toggleRule(rule.id)}
                                    >
                                        {rule.enabled ? __('Pause', 'wp-mcp-connect') : __('Enable', 'wp-mcp-connect')}
                                    </Button>
                                    <Button
                                        variant="tertiary"
                                        size="small"
                                        isDestructive
                                        onClick={() => deleteRule(rule.id)}
                                    >
                                        {__('Delete', 'wp-mcp-connect')}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </Card>
    );
};

export default AutomationRules;
