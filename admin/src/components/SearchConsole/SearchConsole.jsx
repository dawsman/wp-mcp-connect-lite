import { useState, useEffect } from '@wordpress/element';
import { TabPanel, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import GSCConnect from './GSCConnect';
import GSCSiteSelect from './GSCSiteSelect';
import GSCOverview from './GSCOverview';
import GSCPagesTable from './GSCPagesTable';
import GSCInsights from './GSCInsights';
import GSCSettings from './GSCSettings';
import SerpOpportunities from './SerpOpportunities';

const SearchConsole = () => {
    const [connectionStatus, setConnectionStatus] = useState('checking');
    const [statusData, setStatusData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        checkConnectionStatus();
    }, []);

    const checkConnectionStatus = async () => {
        try {
            const response = await apiFetch({ path: '/mcp/v1/gsc/auth/status' });
            setStatusData(response);
            setConnectionStatus(response.status);
        } catch (err) {
            setError(err.message || 'Failed to check connection status');
            setConnectionStatus('error');
        }
    };

    const handleConnect = () => {
        checkConnectionStatus();
    };

    const handleSiteSelect = () => {
        checkConnectionStatus();
    };

    const handleDisconnect = async () => {
        try {
            await apiFetch({ path: '/mcp/v1/gsc/auth/disconnect', method: 'POST' });
            setConnectionStatus('disconnected');
            setStatusData(null);
        } catch (err) {
            setError(err.message || 'Failed to disconnect');
        }
    };

    if (connectionStatus === 'checking') {
        return (
            <div className="gsc-loading">
                <Spinner />
                <p>{__('Checking connection status...', 'wp-mcp-connect')}</p>
            </div>
        );
    }

    if (connectionStatus === 'error') {
        return (
            <Notice status="error" isDismissible={false}>
                {error || __('Failed to check connection status', 'wp-mcp-connect')}
            </Notice>
        );
    }

    if (connectionStatus === 'disconnected' || connectionStatus === 'needs_auth') {
        return <GSCConnect onConnect={handleConnect} />;
    }

    if (connectionStatus === 'needs_site') {
        return <GSCSiteSelect onSelect={handleSiteSelect} />;
    }

    const subTabs = [
        {
            name: 'overview',
            title: __('Overview', 'wp-mcp-connect'),
            className: 'gsc-tab-overview',
        },
        {
            name: 'pages',
            title: __('Pages', 'wp-mcp-connect'),
            className: 'gsc-tab-pages',
        },
        {
            name: 'insights',
            title: __('Insights', 'wp-mcp-connect'),
            className: 'gsc-tab-insights',
        },
        {
            name: 'serp',
            title: __('SERP Opportunities', 'wp-mcp-connect'),
            className: 'gsc-tab-serp',
        },
        {
            name: 'settings',
            title: __('Settings', 'wp-mcp-connect'),
            className: 'gsc-tab-settings',
        },
    ];

    const renderSubTab = (tab) => {
        switch (tab.name) {
            case 'overview':
                return <GSCOverview siteUrl={statusData?.site_url} />;
            case 'pages':
                return <GSCPagesTable />;
            case 'insights':
                return <GSCInsights />;
            case 'serp':
                return <SerpOpportunities />;
            case 'settings':
                return <GSCSettings onDisconnect={handleDisconnect} siteUrl={statusData?.site_url} />;
            default:
                return null;
        }
    };

    return (
        <div className="gsc-content">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <TabPanel
                className="gsc-sub-tabs"
                activeClass="is-active"
                tabs={subTabs}
            >
                {renderSubTab}
            </TabPanel>

            <style>{`
                .gsc-loading {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    padding: 60px 20px;
                }
                .gsc-loading p {
                    margin-top: 15px;
                    color: #666;
                }
                .gsc-sub-tabs {
                    margin-top: 20px;
                }
                .gsc-sub-tabs .components-tab-panel__tabs {
                    margin-bottom: 20px;
                    border-bottom: 1px solid #ddd;
                }
            `}</style>
        </div>
    );
};

export default SearchConsole;
