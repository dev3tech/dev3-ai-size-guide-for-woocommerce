/**
 * Popup / bottom sheet: open/close, focus trap, Escape, overlay click, scroll lock and swipe-down to close.
 */
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

let current = null;

/**
 * Visible focusable elements inside a node.
 *
 * @param {Element} node Node.
 * @return {Element[]} Elements.
 */
function focusables( node ) {
	return Array.from( node.querySelectorAll( FOCUSABLE ) ).filter( ( el ) => ! el.closest( '[hidden]' ) && el.getClientRects().length > 0 );
}

/**
 * Opens a sheet.
 *
 * @param {Element}  sheet   Sheet root.
 * @param {Element}  trigger Element to refocus on close.
 * @param {Function} onOpen  Called after opening.
 */
export function openSheet( sheet, trigger, onOpen ) {
	if ( ! sheet ) {
		return;
	}
	if ( current && current.sheet !== sheet ) {
		closeSheet();
	}
	const panel = sheet.querySelector( '.dev3sg-sheet__panel' );
	sheet.hidden = false;
	document.documentElement.classList.add( 'dev3sg-locked' );
	current = { sheet, trigger, panel };
	// Next frame so the transition runs.
	const raf = window.requestAnimationFrame || ( ( cb ) => setTimeout( cb, 16 ) );
	raf( () => {
		sheet.classList.add( 'is-open' );
		const first = sheet.querySelector( '[data-autofocus]' ) || panel;
		if ( first && first.focus ) {
			first.focus( { preventScroll: true } );
		}
	} );
	if ( onOpen ) {
		onOpen( sheet );
	}
}

/**
 * Closes the open sheet.
 */
export function closeSheet() {
	if ( ! current ) {
		return;
	}
	const { sheet, trigger, panel } = current;
	current = null;
	sheet.classList.remove( 'is-open' );
	sheet.classList.remove( 'is-dragging' );
	if ( panel ) {
		panel.style.transform = '';
	}
	document.documentElement.classList.remove( 'dev3sg-locked' );
	const reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	setTimeout( () => {
		if ( ! sheet.classList.contains( 'is-open' ) ) {
			sheet.hidden = true;
		}
	}, reduce ? 0 : 260 );
	if ( trigger && trigger.focus ) {
		trigger.focus( { preventScroll: true } );
	}
}

export const isOpen = () => !! current;

/**
 * Keyboard: Escape closes, Tab is trapped.
 *
 * @param {KeyboardEvent} e Event.
 */
function onKey( e ) {
	if ( ! current ) {
		return;
	}
	if ( e.key === 'Escape' ) {
		e.preventDefault();
		closeSheet();
		return;
	}
	if ( e.key !== 'Tab' ) {
		return;
	}
	const items = focusables( current.panel );
	if ( ! items.length ) {
		e.preventDefault();
		current.panel.focus();
		return;
	}
	const first = items[ 0 ];
	const last = items[ items.length - 1 ];
	const active = document.activeElement;
	if ( e.shiftKey && ( active === first || active === current.panel ) ) {
		e.preventDefault();
		last.focus();
	} else if ( ! e.shiftKey && active === last ) {
		e.preventDefault();
		first.focus();
	} else if ( ! current.panel.contains( active ) ) {
		e.preventDefault();
		first.focus();
	}
}

/**
 * Swipe down on the grab handle / header closes the sheet (phones).
 *
 * @param {Element} sheet Sheet.
 */
function enableDrag( sheet ) {
	const panel = sheet.querySelector( '.dev3sg-sheet__panel' );
	const handles = sheet.querySelectorAll( '[data-dev3sg-grab], .dev3sg-sheet__head' );
	let startY = null;
	let startT = 0;
	let dy = 0;
	const move = ( e ) => {
		if ( startY === null ) {
			return;
		}
		dy = Math.max( 0, ( e.touches ? e.touches[ 0 ].clientY : e.clientY ) - startY );
		panel.style.transform = `translateY(${ dy }px)`;
	};
	const end = () => {
		if ( startY === null ) {
			return;
		}
		const speed = dy / Math.max( 1, Date.now() - startT );
		startY = null;
		sheet.classList.remove( 'is-dragging' );
		panel.style.transform = '';
		if ( dy > 110 || ( dy > 30 && speed > 0.6 ) ) {
			closeSheet();
		}
		dy = 0;
	};
	handles.forEach( ( handle ) => {
		handle.addEventListener(
			'touchstart',
			( e ) => {
				if ( e.target.closest( 'button' ) ) {
					return;
				}
				startY = e.touches[ 0 ].clientY;
				startT = Date.now();
				sheet.classList.add( 'is-dragging' );
			},
			{ passive: true }
		);
		handle.addEventListener( 'touchmove', move, { passive: true } );
		handle.addEventListener( 'touchend', end );
		handle.addEventListener( 'touchcancel', end );
	} );
}

/**
 * Wires every sheet on the page.
 *
 * @param {Function} onOpen ( sheet, trigger ) => void.
 */
export function initSheets( onOpen ) {
	document.querySelectorAll( '[data-dev3sg-sheet]' ).forEach( ( sheet ) => {
		if ( sheet.dataset.ready ) {
			return;
		}
		sheet.dataset.ready = '1';
		// Move to <body> so theme containers (transform, overflow) cannot clip the fixed overlay.
		if ( sheet.parentNode !== document.body ) {
			document.body.appendChild( sheet );
		}
		sheet.addEventListener( 'click', ( e ) => {
			if ( e.target.closest( '[data-dev3sg-close]' ) ) {
				e.preventDefault();
				closeSheet();
			}
		} );
		enableDrag( sheet );
	} );
	if ( ! document.documentElement.dataset.dev3sgKeys ) {
		document.documentElement.dataset.dev3sgKeys = '1';
		document.addEventListener( 'keydown', onKey );
		document.addEventListener( 'click', ( e ) => {
			const trigger = e.target.closest( '[data-dev3sg-open]' );
			if ( ! trigger ) {
				return;
			}
			e.preventDefault();
			const sheet = document.getElementById( trigger.getAttribute( 'data-dev3sg-open' ) );
			openSheet( sheet, trigger, () => onOpen && onOpen( sheet, trigger ) );
		} );
	}
}
