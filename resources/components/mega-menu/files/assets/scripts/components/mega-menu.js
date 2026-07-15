/**
 * Accessible mega-menu disclosure behavior.
 *
 * @package ST_WP_Starter
 */

(function () {
    const roots = document.querySelectorAll( '.mega-menu' );

    if ( ! roots.length ) {
        return;
    }

    /**
     * Close one disclosure and optionally restore focus to its button.
     *
     * @param {HTMLButtonElement} button Disclosure button.
     * @param {boolean} restoreFocus Whether focus should return to the button.
     */
    const closeDisclosure = ( button, restoreFocus = false ) => {
        const submenuId = button.getAttribute( 'aria-controls' );
        const submenu = submenuId ? document.getElementById( submenuId ) : null;

        button.setAttribute( 'aria-expanded', 'false' );

        if ( submenu ) {
            submenu.querySelectorAll( '.mega-menu__toggle[aria-expanded="true"]' ).forEach( ( childButton ) => {
                closeDisclosure( childButton );
            } );
            submenu.hidden = true;
        }

        if ( restoreFocus ) {
            button.focus();
        }
    };

    /**
     * Close every open disclosure below a menu root.
     *
     * @param {Element} root Menu component root.
     */
    const closeAll = ( root ) => {
        root.querySelectorAll( '.mega-menu__toggle[aria-expanded="true"]' ).forEach( ( button ) => {
            closeDisclosure( button );
        } );
    };

    /**
     * Close disclosures beside a button while preserving its open ancestors.
     *
     * Nested menus need their parent disclosure to remain open. Comparing the
     * buttons' containing lists limits accordion behavior to one menu level.
     *
     * @param {Element} root Menu component root.
     * @param {HTMLButtonElement} currentButton Button being toggled.
     */
    const closeSiblingDisclosures = ( root, currentButton ) => {
        const currentList = currentButton.parentElement ? currentButton.parentElement.parentElement : null;

        root.querySelectorAll( '.mega-menu__toggle[aria-expanded="true"]' ).forEach( ( button ) => {
            const buttonList = button.parentElement ? button.parentElement.parentElement : null;

            if ( button !== currentButton && buttonList === currentList ) {
                closeDisclosure( button );
            }
        } );
    };

    /**
     * Find the disclosure that owns the focused menu level.
     *
     * @param {Element} root Menu component root.
     * @param {EventTarget|null} target Keyboard event target.
     * @return {HTMLButtonElement|null} Closest open disclosure button.
     */
    const disclosureForTarget = ( root, target ) => {
        if ( ! ( target instanceof Element ) ) {
            return null;
        }

        const focusedToggle = target.closest( '.mega-menu__toggle[aria-expanded="true"]' );

        if ( focusedToggle && root.contains( focusedToggle ) ) {
            return focusedToggle;
        }

        const submenu = target.closest( '.mega-menu__sub-menu' );
        const owner = submenu ? submenu.previousElementSibling : null;

        if ( owner && owner.matches( '.mega-menu__toggle[aria-expanded="true"]' ) && root.contains( owner ) ) {
            return owner;
        }

        const openButtons = root.querySelectorAll( '.mega-menu__toggle[aria-expanded="true"]' );

        return openButtons.length ? openButtons[openButtons.length - 1] : null;
    };

    roots.forEach( ( root, rootIndex ) => {
        const buttons = root.querySelectorAll( '.mega-menu__toggle' );

        buttons.forEach( ( button, buttonIndex ) => {
            const submenu = button.nextElementSibling;

            if ( ! submenu || ! submenu.classList.contains( 'mega-menu__sub-menu' ) ) {
                button.remove();
                return;
            }

            const submenuId = submenu.id || `mega-menu-${rootIndex + 1}-submenu-${buttonIndex + 1}`;
            submenu.id = submenuId;
            submenu.hidden = true;
            button.setAttribute( 'aria-controls', submenuId );

            button.addEventListener( 'click', () => {
                const opening = button.getAttribute( 'aria-expanded' ) !== 'true';
                closeSiblingDisclosures( root, button );
                button.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
                submenu.hidden = ! opening;
            } );
        } );

        root.classList.add( 'mega-menu--ready' );

        root.addEventListener( 'keydown', ( event ) => {
            if ( event.key !== 'Escape' ) {
                return;
            }

            const openButton = disclosureForTarget( root, event.target );

            if ( openButton ) {
                event.preventDefault();
                closeDisclosure( openButton, true );
            }
        } );

        root.addEventListener( 'focusout', ( event ) => {
            if ( event.relatedTarget && root.contains( event.relatedTarget ) ) {
                return;
            }

            closeAll( root );
        } );
    } );

    document.addEventListener( 'pointerdown', ( event ) => {
        roots.forEach( ( root ) => {
            if ( ! root.contains( event.target ) ) {
                closeAll( root );
            }
        } );
    } );
})();
