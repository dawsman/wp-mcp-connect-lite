import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import AuditSummary from './AuditSummary';
import SEOAudit from './SEOAudit';
import MediaAudit from './MediaAudit';
import LinksAudit from './LinksAudit';
import ContentAudit from './ContentAudit';
import Log404 from './Log404';
import LinkAudit from './LinkAudit';

const Audits = () => {
    const tabs = [
        { name: 'summary', title: __('Summary', 'wp-mcp-connect'), className: 'audit-tab-summary' },
        { name: 'seo', title: __('SEO', 'wp-mcp-connect'), className: 'audit-tab-seo' },
        { name: 'media', title: __('Media', 'wp-mcp-connect'), className: 'audit-tab-media' },
        { name: 'links', title: __('Links', 'wp-mcp-connect'), className: 'audit-tab-links' },
        { name: 'link-audit', title: __('Link Audit', 'wp-mcp-connect'), className: 'audit-tab-link-audit' },
        { name: 'content', title: __('Content', 'wp-mcp-connect'), className: 'audit-tab-content' },
        { name: 'log404', title: __('404 Log', 'wp-mcp-connect'), className: 'audit-tab-404' },
    ];

    const renderTab = (tab) => {
        switch (tab.name) {
            case 'summary':
                return <AuditSummary />;
            case 'seo':
                return <SEOAudit />;
            case 'media':
                return <MediaAudit />;
            case 'links':
                return <LinksAudit />;
            case 'link-audit':
                return <LinkAudit />;
            case 'content':
                return <ContentAudit />;
            case 'log404':
                return <Log404 />;
            default:
                return null;
        }
    };

    return (
        <div className="wp-mcp-connect-audits">
            <TabPanel className="wp-mcp-connect-sub-tabs" activeClass="is-active" tabs={tabs}>
                {renderTab}
            </TabPanel>
        </div>
    );
};

export default Audits;
