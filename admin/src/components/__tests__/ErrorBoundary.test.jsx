/**
 * @jest-environment jsdom
 */
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';

// Mock @wordpress/element — ErrorBoundary imports { Component } from it.
jest.mock( '@wordpress/element', () => {
	const React = require( 'react' );
	return {
		...React,
		Component: React.Component,
	};
} );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick, ...props } ) => (
		<button onClick={ onClick } { ...props }>{ children }</button>
	),
} ) );

import ErrorBoundary from '../ErrorBoundary';

const ThrowingComponent = () => {
	throw new Error( 'Test error' );
};

const WorkingComponent = () => <div>Works</div>;

describe( 'ErrorBoundary', () => {
	// Suppress console.error for expected errors.
	const originalError = console.error;
	beforeEach( () => {
		console.error = jest.fn();
	} );
	afterEach( () => {
		console.error = originalError;
	} );

	it( 'renders children when no error', () => {
		render(
			<ErrorBoundary>
				<WorkingComponent />
			</ErrorBoundary>
		);
		expect( screen.getByText( 'Works' ) ).toBeInTheDocument();
	} );

	it( 'renders error fallback when child throws', () => {
		render(
			<ErrorBoundary>
				<ThrowingComponent />
			</ErrorBoundary>
		);
		expect( screen.getByText( 'Something went wrong' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Test error' ) ).toBeInTheDocument();
	} );

	it( 'shows Try Again button in error state', () => {
		render(
			<ErrorBoundary>
				<ThrowingComponent />
			</ErrorBoundary>
		);
		expect( screen.getByText( 'Try Again' ) ).toBeInTheDocument();
	} );

	it( 'resets error state when Try Again is clicked', () => {
		// Use a flag so the component only throws on first render.
		let shouldThrow = true;
		const MaybeThrow = () => {
			if ( shouldThrow ) {
				throw new Error( 'Conditional error' );
			}
			return <div>Recovered</div>;
		};

		render(
			<ErrorBoundary>
				<MaybeThrow />
			</ErrorBoundary>
		);

		expect( screen.getByText( 'Something went wrong' ) ).toBeInTheDocument();

		// Stop throwing before clicking retry.
		shouldThrow = false;
		fireEvent.click( screen.getByText( 'Try Again' ) );

		expect( screen.getByText( 'Recovered' ) ).toBeInTheDocument();
	} );

	it( 'shows fallback message for errors without message', () => {
		const ThrowNull = () => {
			throw { notAnError: true };
		};

		render(
			<ErrorBoundary>
				<ThrowNull />
			</ErrorBoundary>
		);
		expect( screen.getByText( 'An unexpected error occurred.' ) ).toBeInTheDocument();
	} );
} );
