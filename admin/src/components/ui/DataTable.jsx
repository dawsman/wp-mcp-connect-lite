import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import clsx from 'clsx';
import SkeletonLoader from './SkeletonLoader';
import EmptyState from './EmptyState';

const DataTable = ({
    columns,
    data,
    loading = false,
    emptyTitle,
    emptyMessage,
    emptyAction,
    sortable = true,
    defaultSort,
    defaultOrder = 'desc',
    onSort,
    page,
    totalPages,
    total,
    onPageChange,
    className,
}) => {
    const [sortBy, setSortBy] = useState(defaultSort || '');
    const [sortOrder, setSortOrder] = useState(defaultOrder);

    const handleSort = (key) => {
        if (!sortable) return;
        const col = columns.find((c) => c.key === key);
        if (!col || col.sortable === false) return;

        const newOrder = sortBy === key && sortOrder === 'desc' ? 'asc' : 'desc';
        setSortBy(key);
        setSortOrder(newOrder);
        if (onSort) {
            onSort(key, newOrder);
        }
    };

    if (loading) {
        return <SkeletonLoader type="table" rows={5} />;
    }

    if (!data || data.length === 0) {
        return (
            <EmptyState
                title={emptyTitle || __('No data', 'wp-mcp-connect')}
                message={emptyMessage || __('There is nothing to display yet.', 'wp-mcp-connect')}
                action={emptyAction}
            />
        );
    }

    return (
        <div className={clsx('mcp-table-wrapper', className)}>
            <table className="mcp-table">
                <thead>
                    <tr>
                        {columns.map((col) => (
                            <th
                                key={col.key}
                                className={clsx(
                                    col.sortable !== false && sortable && 'sortable',
                                    col.className
                                )}
                                style={col.width ? { width: col.width } : undefined}
                                onClick={() =>
                                    col.sortable !== false && sortable
                                        ? handleSort(col.key)
                                        : undefined
                                }
                                {...(col.sortable !== false && sortable ? {
                                    tabIndex: 0,
                                    onKeyDown: (e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            e.preventDefault();
                                            handleSort(col.key);
                                        }
                                    },
                                    'aria-sort': sortBy === col.key
                                        ? (sortOrder === 'asc' ? 'ascending' : 'descending')
                                        : 'none',
                                } : {})}
                            >
                                {col.label}
                                {sortable && col.sortable !== false && sortBy === col.key && (
                                    <span
                                        className={clsx(
                                            'sort-icon',
                                            sortBy === col.key && 'sort-icon--active'
                                        )}
                                    >
                                        {sortOrder === 'asc' ? ' ↑' : ' ↓'}
                                    </span>
                                )}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {data.map((row, i) => (
                        <tr key={row.id || i}>
                            {columns.map((col) => (
                                <td key={col.key} className={col.cellClassName}>
                                    {col.render ? col.render(row[col.key], row) : row[col.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
            {onPageChange && totalPages > 1 && (
                <div className="mcp-pagination">
                    <span>
                        {total !== undefined
                            ? `${total.toLocaleString()} ${__('items', 'wp-mcp-connect')}`
                            : `${__('Page', 'wp-mcp-connect')} ${page} / ${totalPages}`}
                    </span>
                    <div className="mcp-pagination__buttons">
                        <Button
                            variant="secondary"
                            size="small"
                            disabled={page <= 1}
                            onClick={() => onPageChange(page - 1)}
                        >
                            {__('Previous', 'wp-mcp-connect')}
                        </Button>
                        <Button
                            variant="secondary"
                            size="small"
                            disabled={page >= totalPages}
                            onClick={() => onPageChange(page + 1)}
                        >
                            {__('Next', 'wp-mcp-connect')}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default DataTable;
