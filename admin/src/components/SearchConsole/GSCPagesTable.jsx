import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice, SelectControl, TextControl, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { external, download } from '@wordpress/icons';
import apiFetch from '@wordpress/api-fetch';

const GSCPagesTable = () => {
    const [pages, setPages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [total, setTotal] = useState(0);
    const [orderby, setOrderby] = useState('impressions');
    const [order, setOrder] = useState('desc');
    const [indexed, setIndexed] = useState('all');
    const [search, setSearch] = useState('');
    const [selectedPage, setSelectedPage] = useState(null);
    const [exporting, setExporting] = useState(false);
    const [keywordRec, setKeywordRec] = useState(null);
    const [recLoading, setRecLoading] = useState(false);

    useEffect(() => {
        loadPages();
    }, [page, orderby, order, indexed]);

    useEffect(() => {
        const debounce = setTimeout(() => {
            if (page === 1) {
                loadPages();
            } else {
                setPage(1);
            }
        }, 500);
        return () => clearTimeout(debounce);
    }, [search]);

    useEffect(() => {
        setKeywordRec(null);
        setRecLoading(false);
    }, [selectedPage]);

    const loadPages = async () => {
        setLoading(true);
        setError(null);

        try {
            const params = new URLSearchParams({
                page,
                per_page: 25,
                orderby,
                order,
                indexed,
                search,
            });

            const response = await apiFetch({ path: `/mcp/v1/gsc/pages?${params}` });
            setPages(response.pages || []);
            setTotalPages(response.total_pages || 1);
            setTotal(response.total || 0);
        } catch (err) {
            setError(err.message || 'Failed to load pages');
        } finally {
            setLoading(false);
        }
    };

    const handleExport = async () => {
        setExporting(true);

        try {
            const response = await apiFetch({ path: `/mcp/v1/gsc/export?indexed=${indexed}` });

            // Create download
            const blob = new Blob([response.csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = response.filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        } catch (err) {
            setError(err.message || 'Failed to export');
        } finally {
            setExporting(false);
        }
    };

    const loadKeywordRecommendation = async () => {
        if (!selectedPage) return;
        setRecLoading(true);
        setKeywordRec(null);
        try {
            const response = await apiFetch({ path: `/mcp/v1/gsc/keywords/recommend?gsc_id=${selectedPage.id}&show_candidates=true` });
            setKeywordRec(response);
        } catch (err) {
            setKeywordRec({ error: err.message || 'Failed to load recommendation' });
        } finally {
            setRecLoading(false);
        }
    };

    const applyFocusKeyword = async () => {
        if (!keywordRec?.recommended_keyword || !selectedPage?.post) return;
        const type = selectedPage.post.type === 'page' ? 'pages' : 'posts';
        try {
            await apiFetch({
                path: `/wp/v2/${type}/${selectedPage.post.id}`,
                method: 'POST',
                data: { cwp_focus_keyword: keywordRec.recommended_keyword },
            });
            setKeywordRec({ ...keywordRec, applied: true });
        } catch (err) {
            setKeywordRec({ ...keywordRec, error: err.message || 'Failed to apply focus keyword' });
        }
    };

    const handleSort = (column) => {
        if (orderby === column) {
            setOrder(order === 'asc' ? 'desc' : 'asc');
        } else {
            setOrderby(column);
            setOrder('desc');
        }
        setPage(1);
    };

    const getTrendIndicator = (trend) => {
        if (trend === null || trend === undefined) return null;
        if (trend > 0) return <span className="trend trend-up">+{trend}</span>;
        if (trend < 0) return <span className="trend trend-down">{trend}</span>;
        return <span className="trend trend-neutral">0</span>;
    };

    const getPositionTrendIndicator = (trend) => {
        if (trend === null || trend === undefined) return null;
        // For position, positive trend (moving up in position) is improvement
        if (trend > 0) return <span className="trend trend-up">+{trend.toFixed(1)}</span>;
        if (trend < 0) return <span className="trend trend-down">{trend.toFixed(1)}</span>;
        return null;
    };

    const getCTRClass = (ctr) => {
        if (ctr >= 5) return 'ctr-good';
        if (ctr >= 2) return 'ctr-ok';
        return 'ctr-low';
    };

    const getKeywordMatchBadge = (match) => {
        if (match === null) return null;
        if (match >= 70) return <span className="badge badge-success">Good match</span>;
        if (match >= 40) return <span className="badge badge-warning">Partial</span>;
        return <span className="badge badge-danger">Mismatch</span>;
    };

    const truncateUrl = (url, maxLength = 50) => {
        if (!url) return '';
        const path = url.replace(/^https?:\/\/[^/]+/, '');
        if (path.length <= maxLength) return path || '/';
        return path.substring(0, maxLength) + '...';
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    };

    const SortHeader = ({ column, label }) => (
        <th
            className={`sortable ${orderby === column ? 'sorted' : ''}`}
            onClick={() => handleSort(column)}
        >
            {label}
            {orderby === column && (
                <span className="sort-indicator">{order === 'asc' ? ' ↑' : ' ↓'}</span>
            )}
        </th>
    );

    return (
        <div className="gsc-pages-table">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <div className="table-controls">
                <div className="filters">
                    <TextControl
                        placeholder={__('Search URL...', 'wp-mcp-connect')}
                        value={search}
                        onChange={setSearch}
                        className="search-input"
                    />
                    <SelectControl
                        value={indexed}
                        onChange={(val) => { setIndexed(val); setPage(1); }}
                        options={[
                            { value: 'all', label: __('All Pages', 'wp-mcp-connect') },
                            { value: 'yes', label: __('Indexed', 'wp-mcp-connect') },
                            { value: 'no', label: __('Not Indexed', 'wp-mcp-connect') },
                        ]}
                    />
                </div>
                <div className="actions">
                    <span className="total-count">{total} {__('pages', 'wp-mcp-connect')}</span>
                    <Button
                        variant="secondary"
                        icon={download}
                        onClick={handleExport}
                        isBusy={exporting}
                        disabled={exporting}
                    >
                        {__('Export CSV', 'wp-mcp-connect')}
                    </Button>
                </div>
            </div>

            {loading ? (
                <div className="gsc-loading">
                    <Spinner />
                </div>
            ) : pages.length > 0 ? (
                <>
                    <table className="wp-mcp-connect-table">
                        <thead>
                            <tr>
                                <th>{__('Page', 'wp-mcp-connect')}</th>
                                <th>{__('Indexed', 'wp-mcp-connect')}</th>
                                <th>{__('Last Crawled', 'wp-mcp-connect')}</th>
                                <th>{__('Top Keyword', 'wp-mcp-connect')}</th>
                                <SortHeader column="impressions" label={__('Impressions', 'wp-mcp-connect')} />
                                <SortHeader column="clicks" label={__('Clicks', 'wp-mcp-connect')} />
                                <SortHeader column="ctr" label={__('CTR', 'wp-mcp-connect')} />
                                <SortHeader column="position" label={__('Position', 'wp-mcp-connect')} />
                            </tr>
                        </thead>
                        <tbody>
                            {pages.map((pageData) => (
                                <tr key={pageData.id} onClick={() => setSelectedPage(pageData)} className="clickable-row">
                                    <td className="url-cell">
                                        <div className="url-wrapper">
                                            <span className="url" title={pageData.url}>
                                                {truncateUrl(pageData.url)}
                                            </span>
                                            {pageData.post && (
                                                <span className="post-title">{pageData.post.title}</span>
                                            )}
                                        </div>
                                    </td>
                                    <td>
                                        {pageData.is_indexed === true && (
                                            <span className="badge badge-success">Yes</span>
                                        )}
                                        {pageData.is_indexed === false && (
                                            <span className="badge badge-danger">No</span>
                                        )}
                                        {pageData.is_indexed === null && (
                                            <span className="badge badge-secondary">Unknown</span>
                                        )}
                                    </td>
                                    <td>{formatDate(pageData.last_crawl_time)}</td>
                                    <td className="keyword-cell">
                                        {pageData.top_query && (
                                            <>
                                                <span className="keyword">{pageData.top_query}</span>
                                                {getKeywordMatchBadge(pageData.keyword_match)}
                                            </>
                                        )}
                                    </td>
                                    <td>
                                        {pageData.impressions.toLocaleString()}
                                        {getTrendIndicator(pageData.impressions_trend)}
                                    </td>
                                    <td>
                                        {pageData.clicks.toLocaleString()}
                                        {getTrendIndicator(pageData.clicks_trend)}
                                    </td>
                                    <td>
                                        <span className={getCTRClass(pageData.ctr)}>
                                            {pageData.ctr}%
                                        </span>
                                    </td>
                                    <td>
                                        {pageData.avg_position}
                                        {getPositionTrendIndicator(pageData.position_trend)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {totalPages > 1 && (
                        <div className="wp-mcp-connect-pagination">
                            <Button
                                variant="secondary"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                            >
                                {__('Previous', 'wp-mcp-connect')}
                            </Button>
                            <span className="page-info">
                                {__('Page', 'wp-mcp-connect')} {page} {__('of', 'wp-mcp-connect')} {totalPages}
                            </span>
                            <Button
                                variant="secondary"
                                disabled={page >= totalPages}
                                onClick={() => setPage(page + 1)}
                            >
                                {__('Next', 'wp-mcp-connect')}
                            </Button>
                        </div>
                    )}
                </>
            ) : (
                <p className="wp-mcp-connect-empty">
                    {__('No pages found. Try syncing data from Google Search Console.', 'wp-mcp-connect')}
                </p>
            )}

            {selectedPage && (
                <Modal
                    title={__('Page Details', 'wp-mcp-connect')}
                    onRequestClose={() => setSelectedPage(null)}
                    className="gsc-page-modal"
                >
                    <div className="page-details">
                        <div className="detail-section">
                            <h4>{__('URL', 'wp-mcp-connect')}</h4>
                            <a href={selectedPage.url} target="_blank" rel="noopener noreferrer">
                                {selectedPage.url}
                            </a>
                        </div>

                        {selectedPage.post && (
                            <div className="detail-section">
                                <h4>{__('WordPress Post', 'wp-mcp-connect')}</h4>
                                <p>
                                    <strong>{selectedPage.post.title}</strong>
                                    <br />
                                    <span className="badge">{selectedPage.post.type}</span>
                                </p>
                                <Button
                                    variant="secondary"
                                    href={selectedPage.post.edit_url}
                                    target="_blank"
                                    icon={external}
                                >
                                    {__('Edit Post', 'wp-mcp-connect')}
                                </Button>
                            </div>
                        )}

                        <div className="detail-section">
                            <h4>{__('Index Status', 'wp-mcp-connect')}</h4>
                            <p>
                                <strong>{__('Indexed:', 'wp-mcp-connect')}</strong> {selectedPage.is_indexed ? __('Yes', 'wp-mcp-connect') : __('No', 'wp-mcp-connect')}
                                <br />
                                <strong>{__('Status:', 'wp-mcp-connect')}</strong> {selectedPage.index_status || '-'}
                                <br />
                                <strong>{__('Last Crawled:', 'wp-mcp-connect')}</strong> {formatDate(selectedPage.last_crawl_time)}
                            </p>
                        </div>

                        <div className="detail-section">
                            <h4>{__('Performance', 'wp-mcp-connect')}</h4>
                            <div className="metrics-grid">
                                <div className="metric">
                                    <span className="metric-value">{selectedPage.impressions.toLocaleString()}</span>
                                    <span className="metric-label">{__('Impressions', 'wp-mcp-connect')}</span>
                                </div>
                                <div className="metric">
                                    <span className="metric-value">{selectedPage.clicks.toLocaleString()}</span>
                                    <span className="metric-label">{__('Clicks', 'wp-mcp-connect')}</span>
                                </div>
                                <div className="metric">
                                    <span className="metric-value">{selectedPage.ctr}%</span>
                                    <span className="metric-label">{__('CTR', 'wp-mcp-connect')}</span>
                                </div>
                                <div className="metric">
                                    <span className="metric-value">{selectedPage.avg_position}</span>
                                    <span className="metric-label">{__('Avg Position', 'wp-mcp-connect')}</span>
                                </div>
                            </div>
                        </div>

                        {selectedPage.top_query && (
                            <div className="detail-section">
                                <h4>{__('Top Query', 'wp-mcp-connect')}</h4>
                                <p>
                                    <strong>{selectedPage.top_query}</strong>
                                    {selectedPage.post?.focus_keyword && (
                                        <>
                                            <br />
                                            <span className="focus-keyword-compare">
                                                {__('Focus Keyword:', 'wp-mcp-connect')} {selectedPage.post.focus_keyword}
                                                {' '}
                                                {getKeywordMatchBadge(selectedPage.keyword_match)}
                                            </span>
                                        </>
                                    )}
                                </p>
                            </div>
                        )}

                        <div className="detail-section">
                            <h4>{__('Keyword Recommendation', 'wp-mcp-connect')}</h4>
                            {keywordRec?.error && <p className="error">{keywordRec.error}</p>}
                            {keywordRec?.recommended_keyword ? (
                                <div>
                                    <p>
                                        <strong>{keywordRec.recommended_keyword}</strong>
                                        {keywordRec.applied && <span className="badge badge-success">Applied</span>}
                                    </p>
                                    {selectedPage.post && (
                                        <Button variant="secondary" onClick={applyFocusKeyword}>
                                            {__('Apply Focus Keyword', 'wp-mcp-connect')}
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <Button variant="secondary" onClick={loadKeywordRecommendation} isBusy={recLoading} disabled={recLoading}>
                                    {recLoading ? __('Loading...', 'wp-mcp-connect') : __('Get Recommendation', 'wp-mcp-connect')}
                                </Button>
                            )}
                        </div>
                    </div>
                </Modal>
            )}

            <style>{`
                .table-controls {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    margin-bottom: 20px;
                    gap: 20px;
                }
                .filters {
                    display: flex;
                    gap: 15px;
                }
                .search-input {
                    min-width: 250px;
                }
                .actions {
                    display: flex;
                    align-items: center;
                    gap: 15px;
                }
                .total-count {
                    color: #666;
                }
                .sortable {
                    cursor: pointer;
                    user-select: none;
                }
                .sortable:hover {
                    background: #f0f0f0;
                }
                .sort-indicator {
                    margin-left: 5px;
                }
                .clickable-row {
                    cursor: pointer;
                }
                .clickable-row:hover {
                    background: #f9f9f9;
                }
                .url-cell {
                    max-width: 250px;
                }
                .url-wrapper {
                    display: flex;
                    flex-direction: column;
                    gap: 3px;
                }
                .url {
                    font-family: monospace;
                    font-size: 12px;
                    color: #0073aa;
                }
                .post-title {
                    font-size: 11px;
                    color: #666;
                }
                .keyword-cell {
                    max-width: 200px;
                }
                .keyword {
                    display: block;
                    font-size: 12px;
                    margin-bottom: 3px;
                }
                .badge {
                    display: inline-block;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-size: 11px;
                    background: #e0e0e0;
                }
                .badge-success {
                    background: #d4edda;
                    color: #155724;
                }
                .badge-warning {
                    background: #fff3cd;
                    color: #856404;
                }
                .badge-danger {
                    background: #f8d7da;
                    color: #721c24;
                }
                .badge-secondary {
                    background: #e2e3e5;
                    color: #383d41;
                }
                .trend {
                    display: inline-block;
                    margin-left: 5px;
                    font-size: 11px;
                }
                .trend-up {
                    color: #28a745;
                }
                .trend-down {
                    color: #dc3545;
                }
                .trend-neutral {
                    color: #6c757d;
                }
                .ctr-good {
                    color: #28a745;
                    font-weight: 500;
                }
                .ctr-ok {
                    color: #ffc107;
                }
                .ctr-low {
                    color: #dc3545;
                }
                .gsc-page-modal .components-modal__content {
                    min-width: 500px;
                }
                .page-details .detail-section {
                    margin-bottom: 20px;
                    padding-bottom: 15px;
                    border-bottom: 1px solid #eee;
                }
                .page-details .detail-section:last-child {
                    border-bottom: none;
                }
                .page-details h4 {
                    margin: 0 0 10px;
                    font-size: 13px;
                    color: #666;
                    text-transform: uppercase;
                }
                .metrics-grid {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 15px;
                }
                .metric {
                    text-align: center;
                    padding: 10px;
                    background: #f9f9f9;
                    border-radius: 4px;
                }
                .metric-value {
                    display: block;
                    font-size: 18px;
                    font-weight: 600;
                }
                .metric-label {
                    display: block;
                    font-size: 11px;
                    color: #666;
                    margin-top: 5px;
                }
                .focus-keyword-compare {
                    color: #666;
                    font-size: 13px;
                }
                .gsc-loading {
                    display: flex;
                    justify-content: center;
                    padding: 40px;
                }
            `}</style>
        </div>
    );
};

export default GSCPagesTable;
