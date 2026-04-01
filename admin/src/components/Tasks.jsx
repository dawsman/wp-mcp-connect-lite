import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import TasksQueue from './TasksQueue';
import RefreshPlanner from './RefreshPlanner';
import ReportsPanel from './ReportsPanel';
import OpsLog from './OpsLog';

const Tasks = () => {
    const tabs = [
        { name: 'queue', title: __('Queue', 'wp-mcp-connect') },
        { name: 'planner', title: __('Refresh Planner', 'wp-mcp-connect') },
        { name: 'reports', title: __('Reports', 'wp-mcp-connect') },
        { name: 'ops', title: __('Operations', 'wp-mcp-connect') },
    ];

    const renderTab = (tab) => {
        switch (tab.name) {
            case 'queue':
                return <TasksQueue />;
            case 'planner':
                return <RefreshPlanner />;
            case 'reports':
                return <ReportsPanel />;
            case 'ops':
                return <OpsLog />;
            default:
                return null;
        }
    };

    return (
        <div className="wp-mcp-connect-tasks">
            <TabPanel className="wp-mcp-connect-sub-tabs" activeClass="is-active" tabs={tabs}>
                {renderTab}
            </TabPanel>
        </div>
    );
};

export default Tasks;
