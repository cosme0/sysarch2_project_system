<!-- Floating Dispense Cart (site-wide, included via site-footer.php) -->
<div class="cart-float" id="cartFloat">
    <button type="button" class="cart-fab" id="cartFab" aria-label="Open dispense cart">
        <svg class="cart-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="21" r="1"></circle>
            <circle cx="20" cy="21" r="1"></circle>
            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
        </svg>
        <span class="cart-badge" id="cartBadge">0</span>
    </button>
    <div class="cart-panel" id="cartPanel">
        <div class="cart-panel-header">
            <strong>Dispense Cart</strong>
            <button type="button" class="cart-close" id="cartClose" aria-label="Close cart">&times;</button>
        </div>
        <div class="cart-items" id="cartItems"></div>
        <div class="cart-panel-footer">
            <div class="cart-panel-totals">
                <span class="cart-total" id="cartTotal">0 items</span>
                <span class="cart-amount" id="cartAmount">₱0.00</span>
            </div>
            <button type="button" class="btn-dispense" id="cartDispenseBtn" disabled>Proceed to checkout</button>
        </div>
    </div>
</div>

<div class="cart-toast" id="cartToast"></div>

<div class="modal-overlay" id="dispenseConfirmOverlay" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Confirm dispense">
            <h3>Confirm order</h3>
            <div id="dispenseConfirmText" class="cart-confirm-list"></div>
            <p class="dispense-confirm-warn">Medication error check — confirm the prescription matches the items, doses, and quantities below before dispensing.</p>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="dispenseConfirmCancel">Cancel</button>
                <button type="button" class="btn-primary btn-danger-solid" id="dispenseConfirmOk">Continue to payment</button>
            </div>
    </div>
</div>

<script>
(function () {
    const STORAGE_KEY = 'amdpms-cart';
    const cart = {}; // sku -> { name, price, amount, qty, stock }

    const cartFab = document.getElementById('cartFab');
    const cartPanel = document.getElementById('cartPanel');
    const cartClose = document.getElementById('cartClose');
    const cartBadge = document.getElementById('cartBadge');
    const cartItems = document.getElementById('cartItems');
    const cartTotal = document.getElementById('cartTotal');
    const cartAmount = document.getElementById('cartAmount');
    const cartDispenseBtn = document.getElementById('cartDispenseBtn');
    const cartToast = document.getElementById('cartToast');

    const addButtons = document.querySelectorAll('.btn-add');

    // The cart survives a full page navigation (shop -> checkout -> back)
    // because it lives in sessionStorage rather than in memory only.
    function saveCart() {
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(cart)); } catch (e) { /* private mode */ }
    }

    function restoreCart() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            const saved = JSON.parse(raw);
            Object.keys(saved || {}).forEach(function (sku) {
                const item = saved[sku];
                if (item && item.qty > 0) { cart[sku] = item; }
            });
        } catch (e) { /* ignore malformed state */ }
    }

    function clearStoredCart() {
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
    }

    // A paid order is finished with, so the cart is emptied.
    window.AMDPMS_CART_CLEAR = clearStoredCart;

    function peso(n) {
        return '₱' + (Number(n) || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function showToast(msg, isError) {
        cartToast.textContent = msg;
        cartToast.className = 'cart-toast show' + (isError ? ' error' : '');
        clearTimeout(showToast._t);
        showToast._t = setTimeout(function () {
            cartToast.className = 'cart-toast';
        }, 2600);
    }

    function renderCart() {
        let count = 0;
        let amount = 0;
        let html = '';
        const skus = Object.keys(cart);

        skus.forEach(function (sku) {
            const item = cart[sku];
            count += item.qty;
            amount += (Number(item.amount) || 0) * item.qty;
            html += '<div class="cart-item">'
                + '<div class="cart-item-info">'
                + '<strong>' + item.name + '</strong>'
                + '<span>' + item.price + '</span>'
                + '</div>'
                + '<div class="cart-qty-stepper">'
                + '<button type="button" class="cart-step cart-step-minus" data-sku="' + sku + '" aria-label="Decrease quantity">&minus;</button>'
                + '<input type="number" class="cart-qty-input" data-sku="' + sku + '" value="' + item.qty + '" min="1" max="' + item.stock + '" inputmode="numeric" aria-label="Quantity for ' + sku + '">'
                + '<button type="button" class="cart-step cart-step-plus" data-sku="' + sku + '" aria-label="Increase quantity">+</button>'
                + '</div>'
                + '<button type="button" class="cart-item-remove" data-sku="' + sku + '" aria-label="Remove ' + item.name + ' from cart">Remove</button>'
                + '</div>';
        });

        // Keep the empty-state message inside the list (rebuild each render).
        if (count === 0) {
            html = '<p class="cart-empty">Your cart is empty. Add medications to dispense.</p>';
        }

        cartBadge.textContent = count;
        cartBadge.style.display = count > 0 ? 'flex' : 'none';
        cartTotal.textContent = count + (count === 1 ? ' item' : ' items');
        if (cartAmount) cartAmount.textContent = peso(amount);
        cartItems.innerHTML = html;

        cartDispenseBtn.disabled = count === 0;

        cartItems.querySelectorAll('.cart-item-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                delete cart[btn.getAttribute('data-sku')];
                renderCart();
                saveCart();
                if (Object.keys(cart).length === 0) showToast('Cart cleared.');
            });
        });

        cartItems.querySelectorAll('.cart-step').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var sku = btn.getAttribute('data-sku');
                if (!cart[sku]) return;
                var isPlus = btn.classList.contains('cart-step-plus');
                if (isPlus) {
                    if (cart[sku].qty < cart[sku].stock) {
                        cart[sku].qty++;
                    } else {
                        showToast('Only ' + cart[sku].stock + ' unit(s) of ' + cart[sku].name + ' available.', true);
                        return;
                    }
                } else {
                    cart[sku].qty--;
                    if (cart[sku].qty <= 0) {
                        delete cart[sku];
                    }
                }
                renderCart();
                saveCart();
            });
        });

        cartItems.querySelectorAll('.cart-qty-input').forEach(function (input) {
            input.addEventListener('change', function () {
                var sku = input.getAttribute('data-sku');
                if (!cart[sku]) return;
                var val = parseInt(input.value, 10);
                if (isNaN(val) || val < 1) {
                    input.value = cart[sku].qty; // revert invalid
                    return;
                }
                if (val > cart[sku].stock) {
                    showToast('Only ' + cart[sku].stock + ' unit(s) of ' + cart[sku].name + ' available.', true);
                    val = cart[sku].stock;
                }
                cart[sku].qty = val;
                renderCart();
                saveCart();
            });
            // Prevent typing non-numeric characters in the field.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'e' || e.key === 'E' || e.key === '+' || e.key === '-') {
                    e.preventDefault();
                }
            });
        });
    }

    addButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const sku = btn.getAttribute('data-sku');
            const name = btn.getAttribute('data-name');
            const price = btn.getAttribute('data-price');
            const amount = parseFloat(btn.getAttribute('data-amount')) || 0;
            const stock = parseInt(btn.getAttribute('data-stock'), 10);

            if (!cart[sku]) {
                cart[sku] = { name: name, price: price, amount: amount, qty: 0, stock: stock };
            }
            if (cart[sku].qty < cart[sku].stock) {
                cart[sku].qty++;
                renderCart();
                saveCart();
                cartPanel.classList.add('open'); // Auto-open the cart when an item is added
                showToast(name + ' added to cart.');
            } else {
                showToast('Only ' + stock + ' unit(s) of ' + name + ' available.', true);
            }
        });
    });

    // Lets live stock updates (from shop.php polling) re-clamp quantities
    // already sitting in the cart.
    window.AMDPMS_CART = {
        syncStock: function (sku, rawStock) {
            if (!cart[sku]) return;
            var newStock = Math.max(0, parseInt(rawStock, 10) || 0);
            var changed = newStock !== cart[sku].stock;
            cart[sku].stock = newStock;

            if (cart[sku].qty > newStock) {
                cart[sku].qty = newStock;
            }

            if (cart[sku].qty <= 0) {
                delete cart[sku];
                showToast('Item out of stock; removed from cart.', true);
                renderCart();
            } else if (changed) {
                renderCart();
            }
        }
    };

    cartFab.addEventListener('click', function () {
        cartPanel.classList.toggle('open');
    });

    cartClose.addEventListener('click', function () {
        cartPanel.classList.remove('open');
    });

    function buildCartSummary() {
        var skus = Object.keys(cart);
        if (skus.length === 0) return '';
        var total = 0;
        var rows = skus.map(function (sku) {
            var it = cart[sku];
            total += (Number(it.amount) || 0) * it.qty;
            return '<div class="cart-confirm-item"><span>' + it.name + '</span>'
                + '<span>&times; ' + it.qty + ' · ' + peso((Number(it.amount) || 0) * it.qty) + '</span></div>';
        });
        rows.push('<div class="cart-confirm-total"><span>Total due</span><strong>' + peso(total) + '</strong></div>');
        return rows.join('');
    }

    /**
     * Hands the order to checkout.php, which re-prices it from the database and
     * takes the payment before anything is released by the dispenser.
     */
    function goToCheckout() {
        if (Object.keys(cart).length === 0) return;

        const tokenEl = document.querySelector('meta[name="csrf-token"]');
        const form = document.createElement('form');
        form.method = 'post';
        form.action = 'checkout.php';
        form.style.display = 'none';

        const push = function (name, value) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };

        push('action', 'start');
        push('_csrf', tokenEl ? tokenEl.getAttribute('content') : '');

        // price_order() in checkout.php iterates $_POST['items'] and reads
        // $item['sku'] / $item['qty'] directly, so the list must be flat:
        // items[0][sku], items[1][sku], ... An extra [0] level here would
        // nest each item one level too deep and the order would come through
        // as empty.
        Object.keys(cart).forEach(function (sku, i) {
            push('items[' + i + '][sku]', sku);
            push('items[' + i + '][qty]', cart[sku].qty);
        });

        document.body.appendChild(form);
        form.submit();
    }

    cartDispenseBtn.addEventListener('click', function () {
        if (Object.keys(cart).length === 0) return;

        var confirmText = document.getElementById('dispenseConfirmText');
        if (confirmText) confirmText.innerHTML = buildCartSummary();

        var overlay = document.getElementById('dispenseConfirmOverlay');
        if (overlay) {
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
            var okBtn = document.getElementById('dispenseConfirmOk');
            if (okBtn) okBtn.focus();
        } else {
            goToCheckout(); // no overlay available; go straight to payment
        }
    });

    (function initDispenseConfirm() {
        var overlay = document.getElementById('dispenseConfirmOverlay');
        if (!overlay) return;
        var cancelBtn = document.getElementById('dispenseConfirmCancel');
        var okBtn = document.getElementById('dispenseConfirmOk');

        function closeDispenseConfirm() {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        if (cancelBtn) cancelBtn.addEventListener('click', closeDispenseConfirm);
        if (okBtn) okBtn.addEventListener('click', function () {
            closeDispenseConfirm();
            goToCheckout();
        });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeDispenseConfirm();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('open')) closeDispenseConfirm();
        });
    })();

    restoreCart();
    renderCart();
})();
</script>

