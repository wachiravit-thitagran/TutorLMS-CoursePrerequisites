/**
 * The prerequisites editor is only reachable through its admin script, so a crash
 * in that script hides the whole feature: the metabox renders, the checkbox is
 * clickable, and nothing happens. That is exactly what shipped in 1.0.0 —
 * `init` was handed to addEventListener directly, so on any page where the
 * script ran before DOM ready it received the Event object as its `scope`
 * argument and died on `scope.querySelectorAll`.
 *
 * These tests run the real file against the smallest DOM that can answer the
 * question, so they fail loudly if the wiring regresses.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const source = readFileSync(
	join( here, '..', '..', 'assets', 'js', 'admin-rules.js' ),
	'utf8'
);

/**
 * Execute the script with a stub document and return what it exposed.
 *
 * @param {string} readyState Value for document.readyState.
 */
function load( readyState ) {
	const listeners = {};
	const queried = [];

	const document = {
		readyState,
		querySelectorAll( selector ) {
			queried.push( selector );
			return [];
		},
		addEventListener( event, handler ) {
			listeners[ event ] = handler;
		},
	};

	const window = {};

	// eslint-disable-next-line no-new-func
	new Function( 'window', 'document', 'wp', 'tlpRules', source )(
		window,
		document,
		{},
		{}
	);

	return { window, listeners, queried };
}

test( 'binds straight away when the DOM is already parsed', () => {
	const { queried, listeners } = load( 'complete' );

	assert.deepEqual( queried, [ '.tlp-rule-editor' ] );
	assert.equal(
		listeners.DOMContentLoaded,
		undefined,
		'no listener is needed once the document is ready'
	);
} );

test( 'survives being called as a DOMContentLoaded listener', () => {
	const { listeners, queried } = load( 'loading' );

	assert.equal( typeof listeners.DOMContentLoaded, 'function' );
	assert.deepEqual( queried, [], 'nothing is queried before the DOM is ready' );

	// An Event has no querySelectorAll. Passing one used to throw a TypeError
	// and leave the editor unbound.
	listeners.DOMContentLoaded( { type: 'DOMContentLoaded' } );

	assert.deepEqual( queried, [ '.tlp-rule-editor' ] );
} );

test( 'accepts an explicit scope, so the course builder can re-bind', () => {
	const { window } = load( 'complete' );
	const scoped = [];

	window.TLPRuleEditor.init( {
		querySelectorAll( selector ) {
			scoped.push( selector );
			return [];
		},
	} );

	assert.deepEqual( scoped, [ '.tlp-rule-editor' ] );
} );

test( 'falls back to the document when handed something unqueryable', () => {
	const { window, queried } = load( 'complete' );

	queried.length = 0;
	window.TLPRuleEditor.init( 42 );

	assert.deepEqual( queried, [ '.tlp-rule-editor' ] );
} );
