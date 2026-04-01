/**
 * @jest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';

// Mock @wordpress/element to use React.
jest.mock( '@wordpress/element', () => require( 'react' ) );

import Badge from '../ui/Badge';

describe( 'Badge', () => {
	it( 'renders children text', () => {
		render( <Badge variant="success">Active</Badge> );
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
	} );

	it( 'applies variant class', () => {
		const { container } = render( <Badge variant="danger">Error</Badge> );
		expect( container.firstChild ).toHaveClass( 'mcp-badge--danger' );
	} );

	it( 'applies base class', () => {
		const { container } = render( <Badge variant="info">Info</Badge> );
		expect( container.firstChild ).toHaveClass( 'mcp-badge' );
	} );

	it( 'renders with default neutral variant', () => {
		const { container } = render( <Badge>Default</Badge> );
		expect( container.firstChild ).toHaveClass( 'mcp-badge' );
		expect( container.firstChild ).toHaveClass( 'mcp-badge--neutral' );
	} );

	it( 'merges custom className', () => {
		const { container } = render( <Badge className="custom">Test</Badge> );
		expect( container.firstChild ).toHaveClass( 'mcp-badge' );
		expect( container.firstChild ).toHaveClass( 'custom' );
	} );

	it( 'renders as a span element', () => {
		const { container } = render( <Badge>Span</Badge> );
		expect( container.firstChild.tagName ).toBe( 'SPAN' );
	} );
} );
