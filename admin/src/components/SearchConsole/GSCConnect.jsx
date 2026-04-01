import { useState } from '@wordpress/element';
import { Button, Notice, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const GSCConnect = ({ onConnect }) => {
    const [connecting, setConnecting] = useState(false);
    const [error, setError] = useState(null);

    const handleConnect = async () => {
        setConnecting(true);
        setError(null);

        try {
            const response = await apiFetch({ path: '/mcp/v1/gsc/auth/url' });

            // Open OAuth window
            const width = 600;
            const height = 700;
            const left = (window.screen.width - width) / 2;
            const top = (window.screen.height - height) / 2;

            const authWindow = window.open(
                response.url,
                'gsc_oauth',
                `width=${width},height=${height},left=${left},top=${top}`
            );

            // Poll for callback
            const checkInterval = setInterval(() => {
                try {
                    if (authWindow.closed) {
                        clearInterval(checkInterval);
                        setConnecting(false);
                        onConnect();
                        return;
                    }

                    const currentUrl = authWindow.location.href;
                    if (currentUrl.includes('gsc_callback=1')) {
                        const urlParams = new URLSearchParams(authWindow.location.search);
                        const code = urlParams.get('code');
                        const state = urlParams.get('state');

                        if (code) {
                            authWindow.close();
                            clearInterval(checkInterval);
                            handleOAuthCallback(code, state);
                        }
                    }
                } catch (e) {
                    // Cross-origin error, ignore
                }
            }, 500);

        } catch (err) {
            setError(err.message || 'Failed to start authentication');
            setConnecting(false);
        }
    };

    const handleOAuthCallback = async (code, state) => {
        try {
            await apiFetch({
                path: '/mcp/v1/gsc/auth/callback',
                method: 'POST',
                data: { code, state },
            });
            onConnect();
        } catch (err) {
            setError(err.message || 'Failed to complete authentication');
        } finally {
            setConnecting(false);
        }
    };

    return (
        <div className="gsc-connect">
            {error && (
                <Notice status="error" isDismissible onDismiss={() => setError(null)}>
                    {error}
                </Notice>
            )}

            <Card>
                <CardHeader>
                    <h2>{__('Connect to Google Search Console', 'wp-mcp-connect')}</h2>
                </CardHeader>
                <CardBody>
                    <p className="description">
                        {__('Click the button below to authorize access to your Google Search Console data.', 'wp-mcp-connect')}
                    </p>

                    <Button
                        variant="primary"
                        onClick={handleConnect}
                        isBusy={connecting}
                        disabled={connecting}
                    >
                        {connecting
                            ? __('Connecting...', 'wp-mcp-connect')
                            : __('Connect to Google Search Console', 'wp-mcp-connect')
                        }
                    </Button>
                </CardBody>
            </Card>

            <style>{`
                .gsc-connect {
                    max-width: 700px;
                    margin: 20px 0;
                }
                .gsc-connect .description {
                    color: #666;
                    margin-bottom: 20px;
                }
            `}</style>
        </div>
    );
};

export default GSCConnect;
