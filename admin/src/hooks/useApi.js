import { useState, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export const useApi = (endpoint, options = {}) => {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const fetchData = useCallback(async (params = {}) => {
        setLoading(true);
        setError(null);

        try {
            const queryString = new URLSearchParams(params).toString();
            const url = queryString ? `${endpoint}?${queryString}` : endpoint;
            
            const response = await apiFetch({
                path: url,
                ...options,
            });
            
            setData(response);
            return response;
        } catch (err) {
            setError(err.message || 'An error occurred');
            throw err;
        } finally {
            setLoading(false);
        }
    }, [endpoint, options]);

    const postData = useCallback(async (body) => {
        setLoading(true);
        setError(null);

        try {
            const response = await apiFetch({
                path: endpoint,
                method: 'POST',
                data: body,
                ...options,
            });
            
            setData(response);
            return response;
        } catch (err) {
            setError(err.message || 'An error occurred');
            throw err;
        } finally {
            setLoading(false);
        }
    }, [endpoint, options]);

    const deleteData = useCallback(async (id) => {
        setLoading(true);
        setError(null);

        try {
            const response = await apiFetch({
                path: `${endpoint}/${id}`,
                method: 'DELETE',
                ...options,
            });
            
            return response;
        } catch (err) {
            setError(err.message || 'An error occurred');
            throw err;
        } finally {
            setLoading(false);
        }
    }, [endpoint, options]);

    return {
        data,
        loading,
        error,
        fetchData,
        postData,
        deleteData,
        setData,
    };
};

export default useApi;
