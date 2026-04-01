import { useState, lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import clsx from 'clsx';
import ErrorBoundary from './components/ErrorBoundary';
import { SkeletonLoader, ToastProvider } from './components/ui';

const Dashboard = lazy(() => import('./components/Dashboard'));
const Audits = lazy(() => import('./components/Audits'));
const Redirects = lazy(() => import('./components/Redirects'));
const Tasks = lazy(() => import('./components/Tasks'));
const SearchConsole = lazy(() => import('./components/SearchConsole'));
const ApiLog = lazy(() => import('./components/ApiLog'));
const Settings = lazy(() => import('./components/Settings'));
const TopologyGraph = lazy(() => import('./components/TopologyGraph'));

const tabs = [
    { name: 'dashboard', title: __('Dashboard', 'wp-mcp-connect') },
    { name: 'audits', title: __('Audits', 'wp-mcp-connect') },
    { name: 'topology', title: __('Topology', 'wp-mcp-connect') },
    { name: 'tasks', title: __('Tasks', 'wp-mcp-connect') },
    { name: 'search-console', title: __('Search Console', 'wp-mcp-connect') },
    { name: 'redirects', title: __('Redirects', 'wp-mcp-connect') },
    { name: 'api-log', title: __('API Log', 'wp-mcp-connect') },
    { name: 'settings', title: __('Settings', 'wp-mcp-connect') },
];

const App = () => {
    const [activeTab, setActiveTab] = useState('dashboard');

    const renderTab = () => {
        switch (activeTab) {
            case 'dashboard':
                return <Dashboard />;
            case 'audits':
                return <Audits />;
            case 'topology':
                return <TopologyGraph />;
            case 'tasks':
                return <Tasks />;
            case 'search-console':
                return <SearchConsole />;
            case 'redirects':
                return <Redirects />;
            case 'api-log':
                return <ApiLog />;
            case 'settings':
                return <Settings />;
            default:
                return null;
        }
    };

    return (
        <ToastProvider>
            <div className="wp-mcp-connect-admin">
                <div className="admin-header">
                    <h1>{__('WP MCP Connect', 'wp-mcp-connect')}</h1>
                </div>
                <nav className="pill-nav" role="tablist" aria-label={__('Admin sections', 'wp-mcp-connect')}>
                    {tabs.map((tab) => (
                        <button
                            key={tab.name}
                            role="tab"
                            aria-selected={activeTab === tab.name}
                            aria-controls={`tabpanel-${tab.name}`}
                            id={`tab-${tab.name}`}
                            className={clsx(
                                'pill-nav__item',
                                activeTab === tab.name && 'pill-nav__item--active'
                            )}
                            onClick={() => setActiveTab(tab.name)}
                        >
                            {tab.title}
                        </button>
                    ))}
                </nav>
                <div
                    className="admin-canvas"
                    role="tabpanel"
                    id={`tabpanel-${activeTab}`}
                    aria-labelledby={`tab-${activeTab}`}
                >
                    <ErrorBoundary>
                        <Suspense fallback={<div className="mcp-loading"><SkeletonLoader type="card" rows={3} /></div>}>
                            {renderTab()}
                        </Suspense>
                    </ErrorBoundary>
                </div>
            </div>
        </ToastProvider>
    );
};

export default App;
