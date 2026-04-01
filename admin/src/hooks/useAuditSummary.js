import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const useAuditSummary = () => {
    const [seo, setSeo] = useState(null);
    const [media, setMedia] = useState(null);
    const [links, setLinks] = useState(null);
    const [content, setContent] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const fetchSummaries = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const [seoRes, mediaRes, linksRes, contentRes] = await Promise.allSettled([
                apiFetch({ path: '/mcp/v1/seo/audit?per_page=1' }),
                apiFetch({ path: '/mcp/v1/media/audit-summary' }).catch(() => null),
                apiFetch({ path: '/mcp/v1/links/audit-summary' }).catch(() => null),
                apiFetch({ path: '/mcp/v1/content/audit-summary' }).catch(() => null),
            ]);

            if (seoRes.status === 'fulfilled') setSeo(seoRes.value);
            if (mediaRes.status === 'fulfilled') setMedia(mediaRes.value);
            if (linksRes.status === 'fulfilled') setLinks(linksRes.value);
            if (contentRes.status === 'fulfilled') setContent(contentRes.value);
        } catch (err) {
            setError(err.message || 'Failed to load audit summaries');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchSummaries();
    }, [fetchSummaries]);

    return {
        seo,
        media,
        links,
        content,
        loading,
        error,
        refresh: fetchSummaries,
    };
};

export default useAuditSummary;
