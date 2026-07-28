/**
 * Prerequisite editor.
 *
 * Deliberately dependency-free and build-free: this ships as a plain script so
 * a site owner can read and patch it in place, and so the plugin has no npm
 * pipeline to keep alive.
 *
 * Nothing here is a security control. The browser decides what to submit; the
 * server decides what is allowed.
 */
( function () {
	'use strict';

	var config = window.TLPAdmin || {};
	var i18n = config.i18n || {};

	function request( action, data ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', config.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );

		return window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json().then( function ( payload ) {
					return { ok: response.ok && payload.success, payload: payload };
				} );
			} );
	}

	function debounce( fn, wait ) {
		var timer = null;

		return function () {
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	function selectedIds( root ) {
		return Array.prototype.map.call(
			root.querySelectorAll( '.tlp-selected-course' ),
			function ( node ) {
				return parseInt( node.getAttribute( 'data-source-id' ), 10 ) || 0;
			}
		);
	}

	function addCourse( root, course ) {
		if ( selectedIds( root ).indexOf( course.id ) !== -1 ) {
			return;
		}

		var list = root.querySelector( '.tlp-selected-courses' );
		var item = document.createElement( 'li' );

		item.className = 'tlp-selected-course';
		item.setAttribute( 'data-source-id', String( course.id ) );
		var newType = root.querySelector( '.tlp-new-rule-type' );
		item.setAttribute(
			'data-rule-type',
			newType ? newType.value : 'course_completed'
		);

		var title = document.createElement( 'span' );
		title.className = 'tlp-course-title';
		title.textContent = course.title;

		var id = document.createElement( 'code' );
		id.textContent = '#' + course.id;

		var type = document.createElement( 'select' );
		type.className = 'tlp-rule-type';
		type.setAttribute( 'aria-label', 'Prerequisite type' );
		Object.keys( config.ruleTypes || {} ).forEach( function ( slug ) {
			var option = document.createElement( 'option' );
			option.value = slug;
			option.textContent = config.ruleTypes[ slug ];
			option.selected = slug === item.getAttribute( 'data-rule-type' );
			type.appendChild( option );
		} );

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link tlp-remove';
		remove.textContent = i18n.remove || 'Remove';

		item.appendChild( title );
		item.appendChild( id );
		item.appendChild( type );
		item.appendChild( remove );
		list.appendChild( item );
	}

	function renderResults( root, results ) {
		var container = root.querySelector( '.tlp-search-results' );
		container.innerHTML = '';

		if ( ! results.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'tlp-search-empty';
			empty.textContent = i18n.noResults || 'No courses found.';
			container.appendChild( empty );
			container.hidden = false;

			return;
		}

		results.forEach( function ( course ) {
			var item = document.createElement( 'li' );
			var button = document.createElement( 'button' );

			button.type = 'button';
			button.className = 'button-link';
			button.textContent =
				course.title +
				( course.status !== 'publish' ? ' (' + course.status + ')' : '' );

			button.addEventListener( 'click', function () {
				addCourse( root, course );
				container.hidden = true;
				root.querySelector( '.tlp-course-search' ).value = '';
			} );

			item.appendChild( button );
			container.appendChild( item );
		} );

		container.hidden = false;
	}

	function collect( root ) {
		var rules = Array.prototype.map.call(
			root.querySelectorAll( '.tlp-selected-course' ),
			function ( node ) {
				return {
					rule_type:
						( node.querySelector( '.tlp-rule-type' ) || {} ).value ||
						node.getAttribute( 'data-rule-type' ) ||
						'course_completed',
					source_id: parseInt( node.getAttribute( 'data-source-id' ), 10 ) || 0,
				};
			}
		);

		return {
			enabled: root.querySelector( '.tlp-enabled' ).checked,
			logic: root.querySelector( '.tlp-logic' ).value,
			min_required: parseInt( root.querySelector( '.tlp-min-required' ).value, 10 ) || 1,
			visibility: root.querySelector( '.tlp-visibility' ).value,
			locked_text: root.querySelector( '.tlp-locked-text' ).value,
			redirect_to: parseInt( root.querySelector( '.tlp-redirect-to' ).value, 10 ) || 0,
			admin_bypass: root.querySelector( '.tlp-admin-bypass' ).checked,
			instructor_bypass: root.querySelector( '.tlp-instructor-bypass' ).checked,
			rules: rules,
		};
	}

	function setStatus( root, message, isError ) {
		var status = root.querySelector( '.tlp-status' );
		status.textContent = message;
		status.className = 'tlp-status' + ( isError ? ' is-error' : ' is-success' );
	}

	function bind( root ) {
		if ( root.getAttribute( 'data-tlp-bound' ) === '1' ) {
			return;
		}
		root.setAttribute( 'data-tlp-bound', '1' );

		var courseId = parseInt( root.getAttribute( 'data-course-id' ), 10 ) || config.courseId;

		root.querySelector( '.tlp-enabled' ).addEventListener( 'change', function ( event ) {
			root.querySelector( '.tlp-panel' ).hidden = ! event.target.checked;
		} );

		root.querySelector( '.tlp-logic' ).addEventListener( 'change', function ( event ) {
			root.querySelector( '.tlp-minimum' ).hidden = event.target.value !== 'AT_LEAST';
		} );

		root.querySelector( '.tlp-course-search' ).addEventListener(
			'input',
			debounce( function ( event ) {
				var term = event.target.value.trim();
				var container = root.querySelector( '.tlp-search-results' );

				if ( term.length < 2 ) {
					container.hidden = true;

					return;
				}

				var payload = { course_id: courseId, term: term };
				var exclude = selectedIds( root ).concat( [ courseId ] );

				exclude.forEach( function ( id, index ) {
					payload[ 'exclude[' + index + ']' ] = id;
				} );

				request( 'tlp_search_courses', payload ).then( function ( response ) {
					if ( ! response.ok ) {
						return;
					}

					renderResults( root, response.payload.data.results || [] );
				} );
			}, 250 )
		);

		root.addEventListener( 'click', function ( event ) {
			if ( ! event.target.classList.contains( 'tlp-remove' ) ) {
				return;
			}

			event.preventDefault();
			event.target.closest( '.tlp-selected-course' ).remove();
		} );

		root.querySelector( '.tlp-save' ).addEventListener( 'click', function () {
			setStatus( root, '', false );

			request( 'tlp_save_course_rules', {
				course_id: courseId,
				payload: JSON.stringify( collect( root ) ),
			} ).then( function ( response ) {
				if ( response.ok ) {
					setStatus( root, i18n.saved || 'Saved.', false );

					return;
				}

				var data = response.payload && response.payload.data;
				setStatus(
					root,
					( data && data.message ) || i18n.saveFailed || 'Save failed.',
					true
				);
			} );
		} );
	}

	function init( scope ) {
		Array.prototype.forEach.call(
			( scope || document ).querySelectorAll( '.tlp-rule-editor' ),
			bind
		);
	}

	window.TLPRuleEditor = { init: init };

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
