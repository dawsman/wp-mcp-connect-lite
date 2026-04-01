import { useState, useEffect, useRef } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Card, MetricCard, SkeletonLoader } from './ui';

const TopologyGraph = () => {
    const [graphData, setGraphData] = useState(null);
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selectedNode, setSelectedNode] = useState(null);
    const [filter, setFilter] = useState('all'); // all, orphans, dead_ends
    const svgRef = useRef(null);
    const containerRef = useRef(null);
    const [positions, setPositions] = useState({});
    const [svgDimensions, setSvgDimensions] = useState({ width: 800, height: 600 });

    useEffect(() => {
        loadGraph();
    }, []);

    useEffect(() => {
        if (!containerRef.current || !graphData?.nodes?.length) return;
        const observe = () => {
            const w = containerRef.current.clientWidth || 800;
            const h = Math.max(500, Math.round(w * 0.65));
            setSvgDimensions({ width: w, height: h });
            runForceSimulation(graphData.nodes, graphData.edges, w, h);
        };
        observe();
        const ro = new ResizeObserver(observe);
        ro.observe(containerRef.current);
        return () => ro.disconnect();
    }, [graphData, containerRef]);

    const loadGraph = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await apiFetch({ path: '/mcp/v1/topology/graph' });
            setGraphData(data);
            setStats(data.stats);
        } catch (err) {
            setError(err.message || 'Failed to load topology data');
        } finally {
            setLoading(false);
        }
    };

    const runForceSimulation = (nodes, edges, width = 800, height = 600) => {
        // Simple force-directed layout
        const pos = {};

        // Initialize random positions
        nodes.forEach((node) => {
            pos[node.id] = {
                x: Math.random() * width,
                y: Math.random() * height,
                vx: 0,
                vy: 0,
            };
        });

        // Build adjacency for attraction
        const edgeMap = edges.map((e) => ({
            source: Number(e.source),
            target: Number(e.target),
        }));

        // Run 150 iterations of force simulation
        for (let iter = 0; iter < 150; iter++) {
            const alpha = 1 - iter / 150;

            // Repulsion between all nodes
            for (let i = 0; i < nodes.length; i++) {
                for (let j = i + 1; j < nodes.length; j++) {
                    const a = pos[nodes[i].id];
                    const b = pos[nodes[j].id];
                    const dx = b.x - a.x;
                    const dy = b.y - a.y;
                    const dist = Math.max(Math.sqrt(dx * dx + dy * dy), 1);
                    const force = (500 * alpha) / (dist * dist);
                    const fx = (dx / dist) * force;
                    const fy = (dy / dist) * force;
                    a.vx -= fx;
                    a.vy -= fy;
                    b.vx += fx;
                    b.vy += fy;
                }
            }

            // Attraction along edges
            edgeMap.forEach(({ source, target }) => {
                const a = pos[source];
                const b = pos[target];
                if (!a || !b) return;
                const dx = b.x - a.x;
                const dy = b.y - a.y;
                const dist = Math.max(Math.sqrt(dx * dx + dy * dy), 1);
                const force = dist * 0.01 * alpha;
                const fx = (dx / dist) * force;
                const fy = (dy / dist) * force;
                a.vx += fx;
                a.vy += fy;
                b.vx -= fx;
                b.vy -= fy;
            });

            // Center gravity
            nodes.forEach((node) => {
                const p = pos[node.id];
                p.vx += (width / 2 - p.x) * 0.001 * alpha;
                p.vy += (height / 2 - p.y) * 0.001 * alpha;
            });

            // Apply velocities with damping
            nodes.forEach((node) => {
                const p = pos[node.id];
                p.x += p.vx * 0.8;
                p.y += p.vy * 0.8;
                p.vx *= 0.9;
                p.vy *= 0.9;
                // Bounds
                p.x = Math.max(30, Math.min(width - 30, p.x));
                p.y = Math.max(30, Math.min(height - 30, p.y));
            });
        }

        setPositions(pos);
    };

    const handleRebuild = async () => {
        try {
            await apiFetch({ path: '/mcp/v1/topology/rebuild', method: 'POST' });
            setStats((prev) => prev ? { ...prev, is_building: true } : prev);
        } catch (err) {
            setError(err.message || 'Failed to start rebuild');
        }
    };

    const getNodeRadius = (node) => {
        const linkScore = Math.min(1, (node.inlinks || 0) / 10);
        const gscScore  = maxImpressions > 0 ? Math.min(1, (node.impressions || 0) / maxImpressions) : 0;
        const combined  = (linkScore + gscScore) / 2;
        return Math.max(4, Math.min(20, 4 + combined * 16));
    };

    const getNodeColor = (node) => {
        if (node.inlinks === 0) return '#ef4444'; // orphan - red
        if (node.outlinks === 0) return '#f59e0b'; // dead end - amber
        const colors = { post: '#6366f1', page: '#22c55e', product: '#ec4899' };
        return colors[node.type] || '#6b7280';
    };

    const filteredNodes = graphData?.nodes?.filter((node) => {
        if (filter === 'orphans') return node.inlinks === 0;
        if (filter === 'dead_ends') return node.outlinks === 0;
        return true;
    }) || [];

    const filteredNodeIds = new Set(filteredNodes.map((n) => n.id));

    if (loading) {
        return <div className="mcp-loading"><SkeletonLoader type="card" rows={3} /></div>;
    }

    const maxImpressions = graphData?.nodes?.length
        ? Math.max(...(graphData.nodes.map((n) => n.impressions || 0)))
        : 0;

    return (
        <div className="topology-content fade-in">
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
                        label={__('Orphan Pages', 'wp-mcp-connect')}
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
                title={__('Site Topology', 'wp-mcp-connect')}
                actions={
                    <div className="mcp-header-actions">
                        <div className="date-range-selector">
                            {['all', 'orphans', 'dead_ends'].map((f) => (
                                <button
                                    key={f}
                                    className={filter === f ? 'is-active' : ''}
                                    onClick={() => setFilter(f)}
                                >
                                    {f === 'all' ? __('All', 'wp-mcp-connect') :
                                     f === 'orphans' ? __('Orphans', 'wp-mcp-connect') :
                                     __('Dead Ends', 'wp-mcp-connect')}
                                </button>
                            ))}
                        </div>
                        <Button
                            variant="secondary"
                            onClick={handleRebuild}
                            disabled={stats?.is_building}
                        >
                            {stats?.is_building
                                ? __('Rebuilding...', 'wp-mcp-connect')
                                : __('Rebuild Graph', 'wp-mcp-connect')}
                        </Button>
                    </div>
                }
            >
                {graphData?.nodes?.length > 0 ? (
                    <div className="topology-graph-container" ref={containerRef} style={{ width: '100%', minHeight: 500 }}>
                        <svg
                            ref={svgRef}
                            viewBox={`0 0 ${svgDimensions.width} ${svgDimensions.height}`}
                            width="100%"
                            className="topology-graph-svg"
                        >
                            {/* Edges */}
                            {graphData.edges
                                .filter((e) => filteredNodeIds.has(Number(e.source)) && filteredNodeIds.has(Number(e.target)))
                                .map((edge, i) => {
                                    const s = positions[edge.source];
                                    const t = positions[edge.target];
                                    if (!s || !t) return null;
                                    return (
                                        <line
                                            key={`e-${i}`}
                                            x1={s.x} y1={s.y}
                                            x2={t.x} y2={t.y}
                                            stroke="#e5e7eb"
                                            strokeWidth={0.5}
                                            opacity={0.6}
                                        />
                                    );
                                })}

                            {/* Nodes */}
                            {filteredNodes.map((node) => {
                                const p = positions[node.id];
                                if (!p) return null;
                                return (
                                    <g key={node.id} onClick={() => setSelectedNode(node)} style={{ cursor: 'pointer' }}>
                                        <circle
                                            cx={p.x} cy={p.y}
                                            r={getNodeRadius(node)}
                                            fill={getNodeColor(node)}
                                            opacity={selectedNode?.id === node.id ? 1 : 0.7}
                                            stroke={selectedNode?.id === node.id ? '#1f2937' : 'none'}
                                            strokeWidth={2}
                                        />
                                        {getNodeRadius(node) >= 10 && (
                                            <text
                                                x={p.x} y={p.y + getNodeRadius(node) + 12}
                                                textAnchor="middle"
                                                fontSize="8"
                                                fill="#6b7280"
                                            >
                                                {node.title?.substring(0, 20)}
                                            </text>
                                        )}
                                        {node.impressions > 0 && (
                                            <text
                                                x={p.x}
                                                y={p.y + getNodeRadius(node) + 22}
                                                textAnchor="middle"
                                                fontSize="7"
                                                fill="#9ca3af"
                                            >
                                                {node.impressions.toLocaleString()} impr
                                            </text>
                                        )}
                                    </g>
                                );
                            })}
                        </svg>

                        {selectedNode && (
                            <div className="topology-detail-panel">
                                <h4>{selectedNode.title}</h4>
                                <p className="muted">{selectedNode.type}</p>
                                <div className="topology-detail-stats">
                                    <span><strong>{selectedNode.inlinks}</strong> {__('inlinks', 'wp-mcp-connect')}</span>
                                    <span><strong>{selectedNode.outlinks}</strong> {__('outlinks', 'wp-mcp-connect')}</span>
                                    {selectedNode.impressions > 0 && (
                                        <span><strong>{selectedNode.impressions.toLocaleString()}</strong> {__('impr', 'wp-mcp-connect')}</span>
                                    )}
                                </div>
                                <div className="topology-detail-actions">
                                    <Button variant="secondary" href={selectedNode.url} target="_blank" size="small">
                                        {__('View', 'wp-mcp-connect')}
                                    </Button>
                                    <Button variant="tertiary" onClick={() => setSelectedNode(null)} size="small">
                                        {__('Close', 'wp-mcp-connect')}
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="topology-legend">
                            <span><span className="legend-dot" style={{ background: '#6366f1' }} /> {__('Post', 'wp-mcp-connect')}</span>
                            <span><span className="legend-dot" style={{ background: '#22c55e' }} /> {__('Page', 'wp-mcp-connect')}</span>
                            <span><span className="legend-dot" style={{ background: '#ef4444' }} /> {__('Orphan', 'wp-mcp-connect')}</span>
                            <span><span className="legend-dot" style={{ background: '#f59e0b' }} /> {__('Dead End', 'wp-mcp-connect')}</span>
                        </div>
                    </div>
                ) : (
                    <div className="topology-empty">
                        <p>{__('No link data available. Click "Rebuild Graph" to scan your site.', 'wp-mcp-connect')}</p>
                        <Button variant="primary" onClick={handleRebuild}>
                            {__('Rebuild Graph', 'wp-mcp-connect')}
                        </Button>
                    </div>
                )}
            </Card>

            {stats?.last_rebuild && (
                <p className="muted" style={{ textAlign: 'right', marginTop: '-12px' }}>
                    {__('Last rebuilt:', 'wp-mcp-connect')} {stats.last_rebuild}
                </p>
            )}
        </div>
    );
};

export default TopologyGraph;
