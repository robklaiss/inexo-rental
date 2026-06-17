(function () {
  const cartKey = 'inexoRentalCart';
  const moneyFormatter = new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0,
  });
  const dayInMilliseconds = 24 * 60 * 60 * 1000;
  const basePath = String(window.INEXO_BASE_PATH || document.documentElement.dataset.basePath || '').replace(/\/$/, '');
  const cartImageFallback = '/inexo-rental---tu-partner-en-cada-obra.webflow/images/imagen-producto-generico.avif';

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
      item.mode === 'labor' ? JSON.stringify(item.labor_details || {}) : '',
      item.image || '',
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
    if (item.mode === 'labor') {
      return 1;
    }
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

  function freightAmount(distanceKm, config) {
    const distance = Number(distanceKm);
    if (!Number.isFinite(distance) || distance <= 0) {
      return null;
    }
    const perKm = Number(config.perKm || 0);
    const roundTripFactor = Number(config.roundTripFactor || 2);

    return distance * roundTripFactor * perKm;
  }

  function compactNumber(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
      return '0';
    }

    return String(Math.round(number * 100) / 100);
  }

  function laborUnitLabel(unit, amount) {
    const singular = unit === 'hora' || unit === 'semana' || unit === 'unidad' ? unit : 'dia';
    if (Number(amount) === 1) {
      return singular;
    }
    if (singular === 'hora') {
      return 'horas';
    }
    if (singular === 'semana') {
      return 'semanas';
    }
    if (singular === 'unidad') {
      return 'unidades';
    }
    return 'dias';
  }

  function laborCalculationFromValues(workType, timeAmount, workers, areaM2) {
    const basePrice = Math.max(0, Number(workType?.basePrice || 0));
    const workerCost = Math.max(0, Number(workType?.workerCost || 0));
    const timeCost = Math.max(0, Number(workType?.timeCost || 0));
    const areaCostPerM2 = Math.max(0, Number(workType?.areaCostPerM2 || 0));
    const safeTime = Math.max(0.01, Number(timeAmount || 1));
    const safeWorkers = Math.max(1, Math.ceil(Number(workers || 1)));
    const safeArea = Math.max(0, Number(areaM2 || 0));
    const components = {
      base_price: basePrice,
      workers: workerCost * safeWorkers * safeTime,
      time: timeCost * safeTime,
      area_m2: areaCostPerM2 * safeArea,
    };
    const total = components.base_price + components.workers + components.time + components.area_m2;

    return {
      work_type_id: workType?.id || '',
      work_type: workType?.name || '',
      time_amount: safeTime,
      time_unit: workType?.timeUnit || 'dia',
      workers: safeWorkers,
      area_m2: safeArea,
      base_price: basePrice,
      worker_cost: workerCost,
      time_cost: timeCost,
      area_cost_per_m2: areaCostPerM2,
      components,
      total,
    };
  }

  function laborDetailsHtml(item) {
    if (item.mode !== 'labor' || !item.labor_details) {
      return '';
    }
    const details = item.labor_details;
    const timeLabel = laborUnitLabel(details.time_unit, details.time_amount);
    const rows = [
      `Tipo de trabajo: ${details.work_type || ''}`,
      `Tiempo: ${compactNumber(details.time_amount)} ${timeLabel}`,
      `Trabajadores: ${compactNumber(details.workers || 1)}`,
    ];
    if (Number(details.area_m2 || 0) > 0) {
      rows.push(`Area: ${compactNumber(details.area_m2)} m²`);
    }

    return `<small>${rows.map(escapeHtml).join('<br>')}</small>`;
  }

  function cartItemPriceHtml(item) {
    const unitPrice = cartItemUnitPrice(item);
    const subtotal = cartItemSubtotal(item);
    const label = item.price_label || (item.mode === 'rental' ? 'Alquiler' : 'Compra');
    if (item.mode === 'labor') {
      return `
        <div class="app-cart-price">
          <span>${escapeHtml(label || 'Mano de Obra')}</span>
          <strong>${formatMoney(subtotal)}</strong>
          ${laborDetailsHtml(item)}
        </div>
      `;
    }
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

  function cartItemImageHtml(item) {
    const image = item.image || cartImageFallback;

    return `<img class="app-cart-thumb" src="${escapeHtml(appUrl(image))}" alt="${escapeHtml(item.name || 'Producto')}">`;
  }

  function cartTotalHtml(cart) {
    return `
      <div class="app-cart-total">
        <span>Total carrito</span>
        <strong>${formatMoney(cartTotal(cart))}</strong>
      </div>
    `;
  }

  function cartModeLabel(item, rentalLabel) {
    if (item.mode === 'labor') {
      return 'Mano de Obra';
    }
    return item.mode === 'rental' ? rentalLabel : 'Compra';
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
    const quantity = Math.max(1, Number(item.qty || 1));
    if (existing) {
      existing.qty += quantity;
    } else {
      cart.push({ ...item, qty: quantity });
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
          ${cartItemImageHtml(item)}
          <div class="app-cart-copy">
            <a href="${escapeHtml(productUrl(item.url))}">${escapeHtml(item.name)}</a>
            <div>${cartModeLabel(item, 'Reserva de alquiler')} · Cantidad ${escapeHtml(item.mode === 'labor' ? 1 : (item.qty || 1))}</div>
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
          ${cartItemImageHtml(item)}
          <div class="app-cart-copy">
            <a href="${escapeHtml(productUrl(item.url))}">${escapeHtml(item.name)}</a>
            <div>${cartModeLabel(item, 'Alquiler')} · Cantidad ${escapeHtml(item.mode === 'labor' ? 1 : (item.qty || 1))}</div>
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
        image: addButton.dataset.productImage || '',
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
          image: submit.dataset.productImage || '',
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
    const citySelect = form.querySelector('[data-delivery-city]');
    const zoneWrap = form.querySelector('[data-santo-domingo-zone]');
    const zoneInput = form.querySelector('[data-delivery-zone]');
    const syncZoneVisibility = () => {
      const needsZone = citySelect?.value === 'Santo Domingo';
      zoneWrap?.classList.toggle('app-hidden', !needsZone);
      if (zoneInput) {
        zoneInput.required = Boolean(needsZone);
        if (!needsZone) {
          zoneInput.value = '';
        }
      }
    };
    citySelect?.addEventListener('change', syncZoneVisibility);
    syncZoneVisibility();

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

  document.querySelectorAll('[data-labor-form]').forEach((form) => {
    const select = form.elements.work_type_id;
    const total = form.querySelector('[data-labor-total]');
    const breakdown = form.querySelector('[data-labor-breakdown]');
    const areaInput = form.elements.area_m2;
    const selectedWorkType = () => {
      const option = select?.selectedOptions?.[0];
      if (!option) {
        return null;
      }
      return {
        id: option.value,
        name: option.textContent.trim(),
        basePrice: option.dataset.basePrice,
        workerCost: option.dataset.workerCost,
        timeCost: option.dataset.timeCost,
        areaCostPerM2: option.dataset.areaCostPerM2,
        timeUnit: option.dataset.timeUnit || 'dia',
        requiresArea: option.dataset.requiresArea === '1',
      };
    };
    const render = () => {
      const workType = selectedWorkType();
      if (!workType) {
        if (total) {
          total.textContent = 'Por configurar';
        }
        if (breakdown) {
          breakdown.textContent = '';
        }
        return null;
      }
      if (areaInput) {
        areaInput.required = workType.requiresArea;
      }
      const calculation = laborCalculationFromValues(
        workType,
        form.elements.time_amount?.value,
        form.elements.workers?.value,
        form.elements.area_m2?.value
      );
      if (total) {
        total.textContent = formatMoney(calculation.total);
      }
      if (breakdown) {
        const timeLabel = laborUnitLabel(calculation.time_unit, calculation.time_amount);
        const parts = [
          `Tipo de trabajo: ${calculation.work_type}`,
          `Tiempo: ${compactNumber(calculation.time_amount)} ${timeLabel}`,
          `Trabajadores: ${compactNumber(calculation.workers)}`,
          `Area: ${compactNumber(calculation.area_m2)} m²`,
        ];
        breakdown.innerHTML = parts.map((part) => `<span>${escapeHtml(part)}</span>`).join('');
      }

      return calculation;
    };
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    render();

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const submit = form.querySelector('button[data-product-id]');
      const calculation = render();
      if (!submit || !calculation) {
        return;
      }
      addToCart({
        id: submit.dataset.productId,
        name: submit.dataset.productName,
        url: submit.dataset.productUrl,
        mode: 'labor',
        price_label: submit.dataset.priceLabel || 'Mano de Obra',
        unit_price: Number(calculation.total),
        image: submit.dataset.productImage || '',
        qty: 1,
        labor_details: calculation,
      });
      showCartConfirmation(submit.dataset.productName);
      showButtonConfirmation(submit);
    });
  });

  function updateOfferCountdowns() {
    const now = Date.now();
    document.querySelectorAll('[data-offer-countdown]').forEach((node) => {
      const end = Date.parse(node.dataset.offerCountdown || '');
      if (!Number.isFinite(end)) {
        node.textContent = '';
        return;
      }
      const diff = Math.max(0, end - now);
      const days = Math.floor(diff / dayInMilliseconds);
      const hours = Math.floor((diff % dayInMilliseconds) / (60 * 60 * 1000));
      const minutes = Math.floor((diff % (60 * 60 * 1000)) / (60 * 1000));
      const seconds = Math.floor((diff % (60 * 1000)) / 1000);
      const dayLabel = days === 1 ? 'dia' : 'dias';
      const hourLabel = hours === 1 ? 'hora' : 'horas';
      const minuteLabel = minutes === 1 ? 'minuto' : 'minutos';
      const secondLabel = seconds === 1 ? 'segundo' : 'segundos';
      node.textContent = `${days} ${dayLabel} ${hours} ${hourLabel} ${minutes} ${minuteLabel} ${seconds} ${secondLabel}`;
    });
  }

  function setupFreightCalculators() {
    document.querySelectorAll('[data-freight-calculator]').forEach((box) => {
      const input = box.querySelector('[data-distance-km]');
      const truckSelect = box.querySelector('[data-truck-type]');
      const summary = box.querySelector('[data-freight-summary]');
      const render = () => {
        const distance = Number(input?.value || 0);
        const selectedTruck = truckSelect?.selectedOptions?.[0];
        const config = {
          perKm: Number(selectedTruck?.dataset.costPerKm || 0),
          roundTripFactor: Number(box.dataset.roundTripFactor || 2),
        };
        const amount = freightAmount(distance, config);
        if (!summary) {
          return;
        }
        summary.textContent = amount === null || !truckSelect?.value
          ? 'Flete por confirmar'
          : `Flete ida y vuelta estimado: ${formatMoney(amount)} (${(distance * config.roundTripFactor).toFixed(1)} km · ${formatMoney(config.perKm)}/km)`;
      };
      input?.addEventListener('input', render);
      truckSelect?.addEventListener('change', render);
      render();
    });
  }

  window.initInexoMaps = async function initInexoMaps() {
    if (!window.google?.maps?.places) {
      return;
    }

    const originInput = document.querySelector('[data-origin-address-input]');
    if (originInput) {
      originInput.addEventListener('input', () => {
        const form = originInput.form;
        if (form?.elements.company_origin_place_id) {
          form.elements.company_origin_place_id.value = '';
        }
        if (form?.elements.company_origin_lat) {
          form.elements.company_origin_lat.value = '';
        }
        if (form?.elements.company_origin_lng) {
          form.elements.company_origin_lng.value = '';
        }
      });

      const originAutocomplete = new google.maps.places.Autocomplete(originInput, {
        fields: ['formatted_address', 'geometry', 'place_id'],
      });
      originAutocomplete.addListener('place_changed', () => {
        const place = originAutocomplete.getPlace();
        if (!place) {
          return;
        }
        const form = originInput.form;
        if (place.formatted_address) {
          originInput.value = place.formatted_address;
        }
        if (form?.elements.company_origin_place_id) {
          form.elements.company_origin_place_id.value = place.place_id || '';
        }
        const location = place.geometry?.location;
        if (location && form?.elements.company_origin_lat && form?.elements.company_origin_lng) {
          form.elements.company_origin_lat.value = location.lat();
          form.elements.company_origin_lng.value = location.lng();
        }
      });
    }

    const addressInput = document.querySelector('[data-delivery-address]');
    const freightBox = document.querySelector('[data-freight-calculator]');
    if (!addressInput || !freightBox) {
      return;
    }
    addressInput.addEventListener('input', () => {
      const form = addressInput.form;
      if (form?.elements.delivery_place_id) {
        form.elements.delivery_place_id.value = '';
      }
      if (form?.elements.delivery_lat) {
        form.elements.delivery_lat.value = '';
      }
      if (form?.elements.delivery_lng) {
        form.elements.delivery_lng.value = '';
      }
    });
    const autocomplete = new google.maps.places.Autocomplete(addressInput, {
      fields: ['formatted_address', 'geometry', 'place_id'],
    });
    autocomplete.addListener('place_changed', async () => {
      const place = autocomplete.getPlace();
      if (!place) {
        return;
      }
      const form = addressInput.form;
      if (place.formatted_address) {
        addressInput.value = place.formatted_address;
      }
      if (form?.elements.delivery_place_id) {
        form.elements.delivery_place_id.value = place.place_id || '';
      }
      const location = place.geometry?.location;
      if (location && form?.elements.delivery_lat && form?.elements.delivery_lng) {
        form.elements.delivery_lat.value = location.lat();
        form.elements.delivery_lng.value = location.lng();
      }
      const originLat = Number(freightBox.dataset.originLat || '');
      const originLng = Number(freightBox.dataset.originLng || '');
      const origin = Number.isFinite(originLat) && Number.isFinite(originLng)
        ? new google.maps.LatLng(originLat, originLng)
        : freightBox.dataset.originAddress;
      if (!origin || !location || !window.google?.maps?.DistanceMatrixService) {
        return;
      }
      const service = new google.maps.DistanceMatrixService();
      service.getDistanceMatrix({
        origins: [origin],
        destinations: [place.formatted_address || location],
        travelMode: google.maps.TravelMode.DRIVING,
        unitSystem: google.maps.UnitSystem.METRIC,
      }, (response, status) => {
        const element = response?.rows?.[0]?.elements?.[0];
        if (status !== 'OK' || element?.status !== 'OK') {
          return;
        }
        const km = element.distance.value / 1000;
        if (form?.elements.delivery_distance_km) {
          form.elements.delivery_distance_km.value = km.toFixed(2);
          form.elements.delivery_distance_km.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
    });
  };

  updateCartCount();
  renderCartPage();
  renderCheckoutCart();
  setupFreightCalculators();
  updateOfferCountdowns();
  window.setInterval(updateOfferCountdowns, 1000);
  fillProductRows();
  window.addEventListener('resize', scheduleProductRowFill);
})();
