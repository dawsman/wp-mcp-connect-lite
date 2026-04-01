import { useState, useEffect } from '@wordpress/element';
import { Button, Spinner, Notice, Card, CardBody, CardHeader, RadioControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const GSCSiteSelect = ({ onSelect }) => {
    const [sites, setSites] = useState([]);
    const [selectedSite, setSelectedSite] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        loadSites();
    }, []);

    const loadSites = async () => {
        setLoading(true);
        setError(null);

        try {
            const response = await apiFetch({ path: '/mcp/v1/gsc/sites' });
            setSites(response.sites || []);

            // Auto-select current site if available
            const currentSite = response.sites?.find(s => s.is_current_site);
            if (currentSite) {
                setSelectedSite(currentSite.url);
            } else if (response.sites?.length === 1) {
                setSelectedSite(response.sites[0].url);
            }
        } catch (err) {
            setError(err.message || 'Failed to load sites');
        } finally {
            setLoading(false);
        }
    };

    const handleSelect = async () => {
        if (!selectedSite) {
            setError(__('Please select a site', 'wp-mcp-connect'));
            return;
        }

        setSaving(true);
        setError(null);

        try {
            await apiFetch({
                path: '/mcp/v1/gsc/sites',
                method: 'POST',
                data: { site_url: selectedSite },
            });
            onSelect();
        } catch (err) {
            setError(err.message || 'Failed to select site');
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return (
            <div className="gsc-loading">
                <Spinner />
                <p>{__('Loading Search Console sites...', 'wp-mcp-connect')}</p>
            </div>
        );
    }

    const siteOptions = sites.map(site => ({
        label: (
            <span className="site-option">
                <span className="site-url">{site.url}</span>
                {site.is_current_site && (
                    <span className="site-badge recommended">{__('Recommended', 'wp-mcp-connect')}</span>
                )}
                <span className={`site-badge permission-${site.permission?.toLowerCase()}`}>
                    {site.permission}
                </span>
            </span>
        ),
        value: site.url,
    }));

    return (
        <div className="gsc-site-select">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <Card>
                <CardHeader>
                    <h2>{__('Select Search Console Property', 'wp-mcp-connect')}</h2>
                </CardHeader>
                <CardBody>
                    {sites.length > 0 ? (
                        <>
                            <p className="description">
                                {__('Select the Search Console property you want to use. Choose the one that matches this WordPress site.', 'wp-mcp-connect')}
                            </p>

                            <div className="sites-list">
                                <RadioControl
                                    selected={selectedSite}
                                    options={siteOptions}
                                    onChange={setSelectedSite}
                                />
                            </div>

                            <Button
                                variant="primary"
                                onClick={handleSelect}
                                isBusy={saving}
                                disabled={saving || !selectedSite}
                            >
                                {__('Continue', 'wp-mcp-connect')}
                            </Button>
                        </>
                    ) : (
                        <Notice status="warning" isDismissible={false}>
                            {__('No Search Console properties found. Make sure you have access to at least one property in Google Search Console.', 'wp-mcp-connect')}
                        </Notice>
                    )}
                </CardBody>
            </Card>

            <style>{`
                .gsc-site-select {
                    max-width: 700px;
                    margin: 20px 0;
                }
                .gsc-site-select .description {
                    color: #666;
                    margin-bottom: 20px;
                }
                .sites-list {
                    margin-bottom: 20px;
                }
                .site-option {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .site-url {
                    font-family: monospace;
                }
                .site-badge {
                    font-size: 11px;
                    padding: 2px 8px;
                    border-radius: 3px;
                    background: #e0e0e0;
                }
                .site-badge.recommended {
                    background: #d4edda;
                    color: #155724;
                }
                .site-badge.permission-siteowner {
                    background: #cce5ff;
                    color: #004085;
                }
                .gsc-loading {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    padding: 60px 20px;
                }
                .gsc-loading p {
                    margin-top: 15px;
                    color: #666;
                }
            `}</style>
        </div>
    );
};

export default GSCSiteSelect;
