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
            <span class="cart-total" id="cartTotal">0 items</span>
            <button type="button" class="btn-dispense" id="cartDispenseBtn" disabled>Dispense Selected</button>
        </div>
    </div>
</div>

<div class="cart-toast" id="cartToast"></div>

<script>
(function () {
    const cart = {}; // sku -> { name, price, qty, stock }

    const cartFab = document.getElementById('cartFab');
    const cartPanel = document.getElementById('cartPanel');
    const cartClose = document.getElementById('cartClose');
    const cartBadge = document.getElementById('cartBadge');
    const cartItems = document.getElementById('cartItems');
    const cartTotal = document.getElementById('cartTotal');
    const cartDispenseBtn = document.getElementById('cartDispenseBtn');
    const cartToast = document.getElementById('cartToast');

    const addButtons = document.querySelectorAll('.btn-add');

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
        let html = '';
        const skus = Object.keys(cart);

        skus.forEach(function (sku) {
            const item = cart[sku];
            count += item.qty;
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
                + '<button type="button" class="cart-item-remove" data-sku="' + sku + '">Remove</button>'
                + '</div>';
        });

        // Keep the empty-state message inside the list (rebuild each render).
        if (count === 0) {
            html = '<p class="cart-empty">Your cart is empty. Add medications to dispense.</p>';
        }

        cartBadge.textContent = count;
        cartBadge.style.display = count > 0 ? 'flex' : 'none';
        cartTotal.textContent = count + (count === 1 ? ' item' : ' items');
        cartItems.innerHTML = html;

        cartDispenseBtn.disabled = count === 0;

        cartItems.querySelectorAll('.cart-item-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                delete cart[btn.getAttribute('data-sku')];
                renderCart();
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
            const stock = parseInt(btn.getAttribute('data-stock'), 10);

            if (!cart[sku]) {
                cart[sku] = { name: name, price: price, qty: 0, stock: stock };
            }
            if (cart[sku].qty < cart[sku].stock) {
                cart[sku].qty++;
                renderCart();
                cartPanel.classList.add('open'); // Auto-open the cart when an item is added
                showToast(name + ' added to cart.');
            } else {
                showToast('Only ' + stock + ' unit(s) of ' + name + ' available.', true);
            }
        });
    });

    cartFab.addEventListener('click', function () {
        cartPanel.classList.toggle('open');
    });

    cartClose.addEventListener('click', function () {
        cartPanel.classList.remove('open');
    });

    cartDispenseBtn.addEventListener('click', function () {
        if (Object.keys(cart).length === 0) return;

        cartDispenseBtn.disabled = true;
        cartDispenseBtn.textContent = 'Dispensing…';

        const payload = {
            items: Object.keys(cart).map(function (sku) {
                return { sku: sku, qty: cart[sku].qty };
            })
        };

        fetch('log_dispense.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return { ok: false, message: 'Server error.' }; });
        }).then(function (data) {
            if (data.ok) {
                showToast(data.message || 'Dispensed successfully.');
                // Remove only the SKUs that were fully dispensed. This keeps the
                // cart intact for items that were partially rejected or skipped,
                // so the user is never re-dispensed units they already got.
                var fullyDispensed = (data.items || {});
                Object.keys(cart).forEach(function (sku) {
                    if (!fullyDispensed[sku] || fullyDispensed[sku].ok) {
                        delete cart[sku];
                    }
                });
                renderCart();
                // Redirect to the on-screen receipt view showing the most recent N dispensed items.
                // Use a short delay so the toast is visible briefly before navigation.
                setTimeout(function () {
                    window.location = 'receipt.php?recent=' + encodeURIComponent(data.logged || 0);
                }, 600);
            } else {
                showToast(data.message || 'Dispense failed.', true);
                cartDispenseBtn.disabled = Object.keys(cart).length === 0;
            }
            cartDispenseBtn.textContent = 'Dispense Selected';
            cartPanel.classList.remove('open');
        }).catch(function () {
            showToast('Could not reach the server.', true);
            cartDispenseBtn.textContent = 'Dispense Selected';
            cartDispenseBtn.disabled = false;
        });
    });

    renderCart();
})();
</script>

