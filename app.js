(function () {
  const cartKey = 'inexoRentalCart';
  const moneyFormatter = new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0,
  });
  const dayInMilliseconds = 24 * 60 * 60 * 1000;
  const basePath = String(window.INEXO_BASE_PATH || document.documentElement.dataset.basePath || '').replace(/\/$/, '');

  function appUrl(path) {
    const value = String(path || '');
    if (!value || value === '#' || /^[a-z][a-z0-9+.-]*:/i.test(value) || value.startsWith('//')) {
      return value;
    }
    const normalized = value.startsWith('/') ? value : `/${value}`;
    if (basePath && (normalized === basePath || normalized.startsWith(`${basePath}/`))) {
      return normalized;
    }

    return `${basePath}${normalized}`;
  }

  function formatMoney(value) {
    if (value === null || value === undefined || value === '') {
      return 'Precio no disponible';
    }

    const amount = Number(value);
    if (!Number.isFinite(amount)) {
      return 'Precio no disponible';
    }

    return `$ ${moneyFormatter.format(amount)}`;
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;',
    }[char]));
  }

  function productUrl(value) {
    const url = String(value || '');
    return url.startsWith('/producto/') || (basePath && url.startsWith(`${basePath}/producto/`)) ? appUrl(url) : '#';
  }

  function cartItemKey(item) {
    return [
      item.id,
      item.mode,
      item.price_label || '',
      item.rental_plan || '',
      item.start_date || '',
      item.end_date || '',
      item.city || '',
    ].join('|');
  }

  function cartItemUnitPrice(item) {
    const price = Number(item.unit_price);
    return Number.isFinite(price) ? price : null;
  }

  function parseDateInput(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
      return null;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const date = new Date(Date.UTC(year, month - 1, day));
    if (
      date.getUTCFullYear() !== year ||
      date.getUTCMonth() !== month - 1 ||
      date.getUTCDate() !== day
    ) {
      return null;
    }

    return date;
  }

  function rentalDayCountFromDates(startDate, endDate) {
    const start = parseDateInput(startDate);
    const end = parseDateInput(endDate);
    if (!start || !end || end < start) {
      return null;
    }

    return Math.floor((end - start) / dayInMilliseconds) + 1;
  }

  function cartItemRentalDays(item) {
    const savedDays = Number(item.rental_days);
    if (Number.isFinite(savedDays) && savedDays >= 1) {
      return Math.ceil(savedDays);
    }

    return rentalDayCountFromDates(item.start_date, item.end_date) || 1;
  }

  function rentalPlanDays(plan) {
    if (plan === 'semanal') {
      return 7;
    }
    if (plan === 'mensual') {
      return 30;
    }

    return 1;
  }

  function rentalPlanUnitLabel(plan, units) {
    if (plan === 'semanal') {
      return units === 1 ? 'semana' : 'semanas';
    }
    if (plan === 'mensual') {
      return units === 1 ? 'mes' : 'meses';
    }

    return units === 1 ? 'dia' : 'dias';
  }

  function rentalUnitsForPlan(plan, days) {
    return Math.max(1, Math.ceil(days / rentalPlanDays(plan)));
  }

  function cartItemRentalUnits(item) {
    const savedUnits = Number(item.rental_units);
    if (Number.isFinite(savedUnits) && savedUnits >= 1) {
      return Math.ceil(savedUnits);
    }

    return rentalUnitsForPlan(item.rental_plan, cartItemRentalDays(item));
  }

  function cartItemPriceMultiplier(item) {
    return item.mode === 'rental' ? cartItemRentalUnits(item) : 1;
  }

  function cartItemSubtotal(item) {
    const price = cartItemUnitPrice(item);
    if (price === null) {
      return null;
    }

    return price * cartItemPriceMultiplier(item) * Number(item.qty || 1);
  }

  function cartTotal(cart) {
    return cart.reduce((total, item) => total + (cartItemSubtotal(item) || 0), 0);
  }

  function cartItemPriceHtml(item) {
    const unitPrice = cartItemUnitPrice(item);
    const subtotal = cartItemSubtotal(item);
    const label = item.price_label || (item.mode === 'rental' ? 'Alquiler' : 'Compra');
    if (item.mode === 'rental') {
      const days = cartItemRentalDays(item);
      const units = cartItemRentalUnits(item);
      const unitLabel = rentalPlanUnitLabel(item.rental_plan, units);
      const dayLabel = days === 1 ? 'dia' : 'dias';
      const dateRangeDetail = days > 1 && units !== days ? ` (${days} ${dayLabel})` : '';
      const breakdown = unitPrice !== null
        ? `${formatMoney(unitPrice)} x ${units} ${unitLabel}${dateRangeDetail}`
        : `${units} ${unitLabel}${dateRangeDetail}`;

      return `
        <div class="app-cart-price">
          <span>${escapeHtml(label)}</span>
          <strong>${formatMoney(subtotal)}</strong>
          <small>${escapeHtml(breakdown)}${Number(item.qty || 1) > 1 ? ` · Cantidad ${escapeHtml(item.qty || 1)}` : ''}</small>
        </div>
      `;
    }

    return `
      <div class="app-cart-price">
        <span>${escapeHtml(label)}</span>
        <strong>${formatMoney(unitPrice)}</strong>
        ${Number(item.qty || 1) > 1 && subtotal !== null ? `<small>Subtotal ${formatMoney(subtotal)}</small>` : ''}
      </div>
    `;
  }

  function cartTotalHtml(cart) {
    return `
      <div class="app-cart-total">
        <span>Total carrito</span>
        <strong>${formatMoney(cartTotal(cart))}</strong>
      </div>
    `;
  }

  function readCart() {
    try {
      return JSON.parse(localStorage.getItem(cartKey) || '[]');
    } catch (error) {
      return [];
    }
  }

  function writeCart(cart) {
    localStorage.setItem(cartKey, JSON.stringify(cart));
    updateCartCount();
  }

  function addToCart(item) {
    const cart = readCart();
    const itemKey = cartItemKey(item);
    const existing = cart.find((entry) => cartItemKey(entry) === itemKey);
    if (existing) {
      existing.qty += 1;
    } else {
      cart.push({ ...item, qty: 1 });
    }
    writeCart(cart);
  }

  function getCartConfirmation() {
    let confirmation = document.querySelector('[data-cart-confirmation]');
    if (confirmation) {
      return confirmation;
    }

    confirmation = document.createElement('div');
    confirmation.className = 'app-cart-confirmation';
    confirmation.setAttribute('data-cart-confirmation', '');
    confirmation.setAttribute('role', 'status');
    confirmation.setAttribute('aria-live', 'polite');
    document.body.append(confirmation);

    return confirmation;
  }

  let confirmationTimer = 0;
  function showCartConfirmation(itemName) {
    const confirmation = getCartConfirmation();
    const title = document.createElement('strong');
    const detail = document.createElement('span');
    const link = document.createElement('a');

    title.textContent = 'Agregado al carrito';
    detail.textContent = itemName || 'El equipo seleccionado';
    link.href = appUrl('/carrito');
    link.textContent = 'Ver carrito';

    confirmation.replaceChildren(title, detail, link);
    confirmation.classList.add('is-visible');

    clearTimeout(confirmationTimer);
    confirmationTimer = window.setTimeout(() => {
      confirmation.classList.remove('is-visible');
    }, 4200);
  }

  function showButtonConfirmation(button) {
    if (!button) {
      return;
    }

    if (!button.dataset.originalText) {
      button.dataset.originalText = button.textContent;
    }

    button.textContent = 'Agregado';
    button.classList.add('is-added');

    clearTimeout(Number(button.dataset.confirmationTimer || 0));
    button.dataset.confirmationTimer = window.setTimeout(() => {
      button.textContent = button.dataset.originalText;
      button.classList.remove('is-added');
    }, 1800);
  }

  function updateCartCount() {
    const count = readCart().reduce((total, item) => total + Number(item.qty || 1), 0);
    document.querySelectorAll('[data-cart-count]').forEach((node) => {
      node.textContent = `(${count})`;
    });
  }

  function productPlaceholder(src) {
    const cell = document.createElement('div');
    cell.className = 'w-layout-cell app-product-placeholder-cell';
    cell.setAttribute('aria-hidden', 'true');

    const card = document.createElement('article');
    card.className = 'card-producto app-product-placeholder-card';

    const image = document.createElement('img');
    image.src = src;
    image.alt = '';
    image.loading = 'lazy';
    image.className = 'app-product-placeholder-logo';

    card.append(image);
    cell.append(card);

    return cell;
  }

  function fillProductRows() {
    document.querySelectorAll('[data-fill-product-row]').forEach((grid) => {
      grid.querySelectorAll('.app-product-placeholder-cell').forEach((node) => node.remove());

      const productCells = Array.from(grid.children).filter((child) => (
        child.classList.contains('w-layout-cell') &&
        !child.classList.contains('app-product-placeholder-cell')
      ));

      if (!productCells.length) {
        return;
      }

      const columns = getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length;
      const missing = columns > 1 ? (columns - (productCells.length % columns)) % columns : 0;
      const placeholderImage = grid.dataset.placeholderImage;

      for (let index = 0; index < missing; index += 1) {
        grid.append(productPlaceholder(placeholderImage));
      }
    });
  }

  let productFillFrame = 0;
  function scheduleProductRowFill() {
    cancelAnimationFrame(productFillFrame);
    productFillFrame = requestAnimationFrame(fillProductRows);
  }

  function renderCartPage() {
    const target = document.querySelector('[data-cart-page]');
    if (!target) {
      return;
    }

    const cart = readCart();
    if (!cart.length) {
      target.innerHTML = '<p class="app-empty">Todavia no hay equipos en el carrito.</p>';
      return;
    }

    target.innerHTML = `${cart.map((item, index) => {
      const rentalDetails = item.rental_plan
        ? `<small>${escapeHtml(item.rental_plan)} · ${cartItemRentalDays(item)} ${cartItemRentalDays(item) === 1 ? 'dia' : 'dias'} · ${escapeHtml(item.start_date || '')} ${item.end_date ? `a ${escapeHtml(item.end_date)}` : ''} · ${escapeHtml(item.city || '')}</small>`
        : '';

      return `
        <div class="app-cart-item">
          <div>
            <a href="${escapeHtml(productUrl(item.url))}">${escapeHtml(item.name)}</a>
            <div>${item.mode === 'rental' ? 'Reserva de alquiler' : 'Compra'} · Cantidad ${escapeHtml(item.qty || 1)}</div>
            ${rentalDetails}
          </div>
          ${cartItemPriceHtml(item)}
          <button type="button" data-remove-cart="${index}">Quitar</button>
        </div>
      `;
    }).join('')}${cartTotalHtml(cart)}`;
  }

  function renderCheckoutCart() {
    const target = document.querySelector('[data-checkout-cart]');
    if (!target) {
      return;
    }
    const cart = readCart();
    if (!cart.length) {
      target.innerHTML = '<p class="app-empty">El carrito esta vacio. Agrega equipos antes de confirmar.</p>';
      return;
    }
    target.innerHTML = `${cart.map((item) => {
      const rentalDetails = item.rental_plan
        ? `<small>${escapeHtml(item.rental_plan)} · ${cartItemRentalDays(item)} ${cartItemRentalDays(item) === 1 ? 'dia' : 'dias'} · ${escapeHtml(item.start_date || '')} ${item.end_date ? `a ${escapeHtml(item.end_date)}` : ''} · ${escapeHtml(item.city || '')}</small>`
        : '';

      return `
        <div class="app-cart-item">
          <div>
            <a href="${escapeHtml(productUrl(item.url))}">${escapeHtml(item.name)}</a>
            <div>${item.mode === 'rental' ? 'Alquiler' : 'Compra'} · Cantidad ${escapeHtml(item.qty || 1)}</div>
            ${rentalDetails}
          </div>
          ${cartItemPriceHtml(item)}
        </div>
      `;
    }).join('')}${cartTotalHtml(cart)}`;
  }

  document.addEventListener('click', (event) => {
    const openMenu = event.target.closest('[data-open-menu]');
    if (openMenu) {
      const menu = document.querySelector('#mobile-menu');
      if (menu) {
        menu.classList.add('is-open');
        menu.setAttribute('aria-hidden', 'false');
      }
    }

    const closeMenu = event.target.closest('[data-close-menu]');
    if (closeMenu) {
      const menu = document.querySelector('#mobile-menu');
      if (menu) {
        menu.classList.remove('is-open');
        menu.setAttribute('aria-hidden', 'true');
      }
    }

    const thumb = event.target.closest('[data-image]');
    if (thumb) {
      const main = document.querySelector('[data-main-image]');
      if (main) {
        main.src = thumb.getAttribute('data-image');
      }
      document.querySelectorAll('.app-thumb').forEach((node) => node.classList.remove('is-active'));
      thumb.classList.add('is-active');
    }

    const addButton = event.target.closest('[data-add-cart]');
    if (addButton) {
      addToCart({
        id: addButton.dataset.productId,
        name: addButton.dataset.productName,
        url: addButton.dataset.productUrl,
        mode: 'purchase',
        price_label: addButton.dataset.priceLabel,
        unit_price: Number(addButton.dataset.unitPrice),
      });
      showCartConfirmation(addButton.dataset.productName);
      showButtonConfirmation(addButton);
    }

    const removeButton = event.target.closest('[data-remove-cart]');
    if (removeButton) {
      const cart = readCart();
      cart.splice(Number(removeButton.dataset.removeCart), 1);
      writeCart(cart);
      renderCartPage();
    }
  });

  document.querySelectorAll('[data-reservation-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submit = form.querySelector('.app-reserve-button');
      const message = form.querySelector('[data-form-message]');
      const data = Object.fromEntries(new FormData(form).entries());
      const rentalDays = rentalDayCountFromDates(data.start_date, data.end_date);
      if (!rentalDays) {
        if (message) {
          message.textContent = 'La fecha de fin debe ser igual o posterior a la fecha de inicio.';
          message.style.color = '#a60000';
        }
        return;
      }
      const selectedPlan = form.querySelector('input[name="rental_plan"]:checked');
      const rentalUnits = rentalUnitsForPlan(data.rental_plan, rentalDays);

      try {
        const response = await fetch(appUrl('/api/reservas'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(data),
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error('No se pudo registrar la solicitud.');
        }
        addToCart({
          id: submit.dataset.productId,
          name: submit.dataset.productName,
          url: submit.dataset.productUrl,
          mode: 'rental',
          rental_plan: data.rental_plan,
          price_label: `Alquiler ${data.rental_plan || ''}`.trim(),
          unit_price: Number(selectedPlan?.dataset.unitPrice),
          rental_days: rentalDays,
          rental_units: rentalUnits,
          start_date: data.start_date,
          end_date: data.end_date,
          city: data.city,
        });
        showCartConfirmation(submit.dataset.productName);
        showButtonConfirmation(submit);
        if (message) {
          message.style.color = '';
          message.textContent = payload.message;
        }
      } catch (error) {
        if (message) {
          message.textContent = error.message;
          message.style.color = '#a60000';
        }
      }
    });
  });

  document.querySelectorAll('[data-checkout-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = form.querySelector('[data-checkout-message]');
      const cart = readCart();
      if (!cart.length) {
        if (message) {
          message.textContent = 'El carrito esta vacio.';
          message.style.color = '#a60000';
        }
        return;
      }

      try {
        const response = await fetch(appUrl('/api/checkout'), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            customer: Object.fromEntries(new FormData(form).entries()),
            items: cart,
          }),
        });
        const payload = await response.json();
        if (!response.ok || !payload.ok) {
          throw new Error(payload.message || 'No se pudo registrar el pedido.');
        }
        localStorage.removeItem(cartKey);
        updateCartCount();
        renderCheckoutCart();
        if (message) {
          message.style.color = '#176b25';
          message.innerHTML = `${payload.message}<br><a href="${payload.account_url || appUrl('/cuenta')}">Ver mi cuenta</a>`;
        }
      } catch (error) {
        if (message) {
          message.textContent = error.message;
          message.style.color = '#a60000';
        }
      }
    });
  });

  updateCartCount();
  renderCartPage();
  renderCheckoutCart();
  fillProductRows();
  window.addEventListener('resize', scheduleProductRowFill);
})();
