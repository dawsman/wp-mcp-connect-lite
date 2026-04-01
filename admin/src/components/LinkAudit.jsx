import { useState, useEffect } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, Badge, DataTable, MetricCard, SkeletonLoader } from './ui';

const LinkAudit = () => {
    const [results, setResults] = useState([]);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [filter, setFilter] = useState('all');
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);

    useEffect(() => {
        loadAudit();
    }, [filter, page]);

    const loadAudit = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await apiFetch({
                path: `/mcp/v1/topology/audit?filter=${filter}&page=${page}&per_page=50`,
            });
            setResults(data.results || []);
            setStats(data.stats);
            const total = filter === 'orphans' ? data.stats?.orphan_pages :
                          filter === 'dead_ends' ? data.stats?.dead_ends :
                          data.stats?.total_nodes;
            setTotalPages(Math.ceil((total || 0) / 50));
        } catch (err) {
            setError(err.message || 'Failed to load link audit');
        } finally {
            setLoading(false);
        }
    };

    const handleRebuild = async () => {
        try {
            await apiFetch({ path: '/mcp/v1/topology/rebuild', method: 'POST' });
            setStats((prev) => prev ? { ...prev, is_building: true } : prev);
        } catch (err) {
            setError(err.message || 'Failed to start rebuild');
        }
    };

    const columns = [
        {
            key: 'post_title',
            label: __('Page', 'wp-mcp-connect'),
            render: (val, row) => (
                <a href={row.edit_url} target="_blank" rel="noopener noreferrer">
                    {val || `Post #${row.ID}`}
                </a>
            ),
        },
        {
            key: 'post_type',
            label: __('Type', 'wp-mcp-connect'),
            render: (val) => <Badge variant="neutral">{val}</Badge>,
        },
        {
            key: 'inlink_count',
            label: __('Inlinks', 'wp-mcp-connect'),
            render: (val) => (
                <Badge variant={Number(val) === 0 ? 'danger' : Number(val) < 3 ? 'warning' : 'success'}>
                    {val ?? '\u2014'}
                </Badge>
            ),
            sortable: true,
        },
        {
            key: 'outlink_count',
            label: __('Outlinks', 'wp-mcp-connect'),
            render: (val) => (
                <Badge variant={Number(val) === 0 ? 'danger' : 'neutral'}>
                    {val ?? '\u2014'}
                </Badge>
            ),
            sortable: true,
        },
    ];

    return (
        <div className="link-audit-content fade-in">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            {stats && (
                <div className="grid grid-cols-4 grid-auto">
                    <MetricCard label={__('Total Links', 'wp-mcp-connect')} value={stats.total_links || 0} />
                    <MetricCard label={__('Connected Pages', 'wp-mcp-connect')} value={stats.total_nodes || 0} />
                    <MetricCard
                        label={__('Orphans', 'wp-mcp-connect')}
                        value={stats.orphan_pages || 0}
                        className={stats.orphan_pages > 0 ? 'stat-warning' : 'stat-success'}
                    />
                    <MetricCard
                        label={__('Dead Ends', 'wp-mcp-connect')}
                        value={stats.dead_ends || 0}
                        className={stats.dead_ends > 0 ? 'stat-warning' : 'stat-success'}
                    />
                </div>
            )}

            <Card
                title={__('Internal Link Audit', 'wp-mcp-connect')}
                actions={
                    <div className="mcp-header-actions">
                        <div className="date-range-selector">
                            {[
                                { key: 'all', label: __('All Pages', 'wp-mcp-connect') },
                                { key: 'orphans', label: __('Orphans (0 inlinks)', 'wp-mcp-connect') },
                                { key: 'dead_ends', label: __('Dead Ends (0 outlinks)', 'wp-mcp-connect') },
                            ].map((f) => (
                                <button
                                    key={f.key}
                                    className={filter === f.key ? 'is-active' : ''}
                                    onClick={() => { setFilter(f.key); setPage(1); }}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>
                        <Button variant="secondary" onClick={handleRebuild} disabled={stats?.is_building}>
                            {stats?.is_building ? __('Rebuilding...', 'wp-mcp-connect') : __('Rebuild', 'wp-mcp-connect')}
                        </Button>
                    </div>
                }
            >
                <DataTable
                    columns={columns}
                    data={results.map((r) => ({ ...r, id: r.ID }))}
                    loading={loading}
                    emptyMessage={__('No pages found. Run a rebuild to scan internal links.', 'wp-mcp-connect')}
                    page={page}
                    totalPages={totalPages}
                    onPageChange={setPage}
                />
            </Card>
        </div>
    );
};

export default LinkAudit;
