import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const useGSCTrends = (initialPeriod = '28d') => {
    const [data, setData] = useState(null);
    const [period, setPeriod] = useState(initialPeriod);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const fetchTrends = useCallback(async (p) => {
        setLoading(true);
        setError(null);

        try {
            const days = p === '7d' ? 7 : p === '90d' ? 90 : 28;
            const response = await apiFetch({
                path: `/mcp/v1/gsc/trends?days=${days}`,
            });
            setData(response);
        } catch (err) {
            setError(err.message || 'Failed to load trend data');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchTrends(period);
    }, [period, fetchTrends]);

    return {
        data,
        period,
        setPeriod,
        loading,
        error,
        refresh: () => fetchTrends(period),
    };
};

export default useGSCTrends;
