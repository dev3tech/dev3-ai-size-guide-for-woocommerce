/**
 * Storefront entry: wires size guides, popups / bottom sheets and fit quizzes. No dependencies.
 */
import { initSheets, closeSheet, isOpen } from './sheet';
import { initGuide, selectTab, track } from './guide';

const opened = {};

/**
 * After "Select size": close the popup and bring the size dropdown into view.
 *
 * @param {string}                 size   Size.
 * @param {HTMLSelectElement|null} select Changed select.
 */
function onPick( size, select ) {
	if ( ! select ) {
		return;
	}
	if ( isOpen() ) {
		closeSheet();
	}
	setTimeout( () => {
		if ( select.scrollIntoView ) {
			select.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}
		select.focus( { preventScroll: true } );
	}, 280 );
}

export function init() {
	document.querySelectorAll( '[data-dev3sg-guide]' ).forEach( ( guide ) => initGuide( guide, onPick ) );
	initSheets( ( sheet, trigger ) => {
		const guide = sheet.querySelector( '[data-dev3sg-guide]' );
		if ( ! guide ) {
			return;
		}
		const tab = trigger && trigger.getAttribute( 'data-dev3sg-tab' );
		selectTab( guide, tab === 'quiz' ? 'quiz' : 0 );
		if ( tab === 'quiz' ) {
			const first = guide.querySelector( '[data-panel="quiz"] input[data-measure]' );
			if ( first ) {
				setTimeout( () => first.focus( { preventScroll: true } ), 300 );
			}
		}
		if ( ! opened[ sheet.id ] ) {
			opened[ sheet.id ] = true;
			track( 'open', { chart_id: Number( guide.dataset.chart ), product_id: Number( guide.dataset.product ) } );
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
