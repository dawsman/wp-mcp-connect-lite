import { Component } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="mcp-error-boundary">
                    <h3>{__('Something went wrong', 'wp-mcp-connect')}</h3>
                    <p>{this.state.error?.message || __('An unexpected error occurred.', 'wp-mcp-connect')}</p>
                    <Button
                        variant="secondary"
                        onClick={() => this.setState({ hasError: false, error: null })}
                    >
                        {__('Try Again', 'wp-mcp-connect')}
                    </Button>
                </div>
            );
        }

        return this.props.children;
    }
}

export default ErrorBoundary;
