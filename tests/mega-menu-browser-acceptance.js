async () => {
	// Run in Chrome's page context against the local seeded Mega Menu Demo menu.
	const root = document.querySelector( '[data-st-toolkit-mega-menu]' );
	const checks = [];
	const check = ( condition, message ) => {
		if ( ! condition ) {
			throw new Error( message );
		}
		checks.push( message );
	};
	const pause = () => new Promise( resolve => setTimeout( resolve, 260 ) );
	check( root?.classList.contains( 'st-toolkit-mega-menu--ready' ), 'Component initialized' );
	const desktop = getComputedStyle( root.querySelector( '.st-toolkit-mega-menu__breakpoint' ) ).display !== 'none';
	const toggle = document.querySelector( '.menu-toggle' );
	const close = root.querySelector( '.st-toolkit-mega-menu__drawer-close' );
	close.click();
	await pause();

	if ( desktop ) {
		for ( const trigger of root.querySelectorAll( '.st-toolkit-mega-menu__trigger' ) ) {
			trigger.focus();
			trigger.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'ArrowDown', bubbles: true, cancelable: true } ) );
			await pause();
			const panel = document.getElementById( trigger.getAttribute( 'aria-controls' ) );
			check( ! panel.hidden && panel.contains( document.activeElement ), 'Keyboard opens ' + trigger.textContent.trim() );
			document.activeElement.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true, cancelable: true } ) );
			await pause();
			check( panel.hidden && document.activeElement === trigger, 'Escape closes and restores focus' );
		}
	} else {
		toggle.click();
		await pause();
		for ( const path of [ [ 'Products', 'Platform' ], [ 'Services', 'Strategy' ], [ 'Explore', 'Build', 'Front-end', 'CSS Architecture' ] ] ) {
			for ( const label of path ) {
				const trigger = [ ...root.querySelectorAll( '.st-toolkit-mega-menu__trigger, .st-toolkit-mega-menu__drawer-trigger' ) ]
					.find( element => element.textContent.trim() === label && ! element.closest( '[inert]' ) );
				check( Boolean( trigger ), 'Accessible trigger: ' + label );
				trigger.click();
				await pause();
				const panel = document.getElementById( trigger.getAttribute( 'aria-controls' ) );
				check( ! panel.hidden && panel.getBoundingClientRect().width > 0, 'Visible drawer: ' + label );
				check( panel.contains( document.activeElement ), 'Focus enters drawer: ' + label );
			}
			const panels = root.querySelectorAll( '.st-toolkit-mega-menu__sub-menu--active' );
			const panel = panels[ panels.length - 1 ];
			const links = [ ...panel.querySelectorAll( 'a[href]' ) ].filter( element => ! element.closest( '[inert]' ) && element.offsetParent !== null );
			const last = links[ links.length - 1 ];
			check( Boolean( last ), 'Leaf links exist' );
			last.focus();
			last.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Tab', bubbles: true, cancelable: true } ) );
			check( document.activeElement === close, 'Tab wraps inside the active drawer' );
			close.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true } ) );
			check( document.activeElement === last, 'Shift+Tab returns to the active last link' );
			for ( const _label of path ) {
				document.activeElement.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true, cancelable: true } ) );
				await pause();
			}
			check( root.querySelectorAll( '.st-toolkit-mega-menu__sub-menu--active' ).length === 0, 'Escape unwinds the hierarchy' );
		}
		close.click();
		check( document.activeElement === toggle, 'Closing restores burger focus' );
		check( ! document.body.classList.contains( 'st-toolkit-mega-menu-scroll-locked' ), 'Closing restores page scrolling' );
	}

	const search = root.querySelector( '.st-toolkit-mega-menu__search-toggle' );
	search.click();
	await pause();
	const form = root.querySelector( '.st-toolkit-mega-menu__search' );
	const input = form.querySelector( 'input[type=search]' );
	check( document.activeElement === input, 'Search focuses its input' );
	const bounds = form.getBoundingClientRect();
	check( bounds.left >= -1 && bounds.right <= innerWidth + 1, 'Search fits the viewport' );
	input.dispatchEvent( new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true, cancelable: true } ) );
	check( form.hidden && document.activeElement === search, 'Search Escape restores focus' );
	check( document.documentElement.scrollWidth <= innerWidth, 'No horizontal overflow' );
	return { width: innerWidth, mode: desktop ? 'desktop' : 'mobile', passed: checks.length, checks };
}
