/**
 * Tutor LMS 4.x course-builder bridge.
 *
 * Tutor owns the React tree. We register one React component in its supported
 * extension slot, then reuse the server-rendered editor and the same AJAX save
 * path as the classic editor.
 */
( function () {
	'use strict';

	var config = window.TLPAdmin || {};
	var element = window.wp && window.wp.element;
	var attempts = 0;

	if ( ! element ) {
		return;
	}

	function requestEditor( courseId ) {
		var body = new window.FormData();
		body.append( 'action', 'tlp_get_course_rules' );
		body.append( 'nonce', config.nonce );
		body.append( 'course_id', String( courseId ) );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function Editor() {
		var container = element.useRef( null );
		var state = element.useState( '' );
		var html = state[ 0 ];
		var setHtml = state[ 1 ];
		var courseId =
			parseInt( config.courseId, 10 ) ||
			parseInt( ( window._tutorobject || {} ).ID, 10 ) ||
			0;

		element.useEffect(
			function () {
				if ( courseId <= 0 ) {
					return;
				}

				requestEditor( courseId ).then( function ( payload ) {
					if ( payload && payload.success && payload.data ) {
						setHtml( payload.data.html || '' );
					}
				} );
			},
			[ courseId ]
		);

		element.useEffect(
			function () {
				if (
					html &&
					container.current &&
					window.TLPRuleEditor
				) {
					window.TLPRuleEditor.init( container.current );
				}
			},
			[ html ]
		);

		return element.createElement( 'div', {
			ref: container,
			className: 'tlp-course-builder-prerequisites',
			dangerouslySetInnerHTML: { __html: html },
		} );
	}

	function register() {
		var additional =
			window.Tutor &&
			window.Tutor.CourseBuilder &&
			window.Tutor.CourseBuilder.Additional;

		if ( additional && typeof additional.registerContent === 'function' ) {
			additional.registerContent( 'bottom_of_sidebar', {
				component: element.createElement( Editor ),
				priority: 30,
			} );
			return;
		}

		attempts += 1;
		if ( attempts < 100 ) {
			window.setTimeout( register, 100 );
		}
	}

	register();
} )();
