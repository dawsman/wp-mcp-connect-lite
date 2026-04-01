import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const useDashboardData = () => {
    const [stats, setStats] = useState(null);
    const [systemInfo, setSystemInfo] = useState(null);
    const [recentLogs, setRecentLogs] = useState([]);
    const [gscOverview, setGscOverview] = useState(null);
    const [insights, setInsights] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const fetchAll = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const [statsRes, systemRes, logsRes, gscRes, insightsRes] = await Promise.allSettled([
                apiFetch({ path: '/mcp/v1/dashboard/stats' }),
                apiFetch({ path: '/mcp/v1/system-info' }),
                apiFetch({ path: '/mcp/v1/logs?per_page=5' }),
                apiFetch({ path: '/mcp/v1/gsc/overview' }).catch(() => null),
                apiFetch({ path: '/mcp/v1/gsc/insights?limit=5' }).catch(() => null),
            ]);

            if (statsRes.status === 'fulfilled') setStats(statsRes.value);
            if (systemRes.status === 'fulfilled') setSystemInfo(systemRes.value);
            if (logsRes.status === 'fulfilled') setRecentLogs(logsRes.value);
            if (gscRes.status === 'fulfilled') setGscOverview(gscRes.value);
            if (insightsRes.status === 'fulfilled') setInsights(insightsRes.value);
        } catch (err) {
            setError(err.message || 'Failed to load dashboard data');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAll();
    }, [fetchAll]);

    return {
        stats,
        systemInfo,
        recentLogs,
        gscOverview,
        insights,
        loading,
        error,
        refresh: fetchAll,
    };
};

export default useDashboardData;
