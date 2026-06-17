(function () {
  const cartKey = 'inexoRentalCart';
  const moneyFormatter = new Intl.NumberFormat('en-US', {
    maximumFractionDigits: 0,
  });
  const dayInMilliseconds = 24 * 60 * 60 * 1000;
  const basePath = String(window.INEXO_BASE_PATH || document.documentElement.dataset.basePath || '').replace(/\/$/, '');
  const cartImageFallback = '/inexo-rental---tu-partner-en-cada-obra.webflow/images/imagen-producto-generico.avif';
  const truckIconPath = '/assets/icono-camion.png';

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
      item.area_m2 || '',
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
    if (!start || !end || end <= start) {
      return null;
    }

    return Math.floor((end - start) / dayInMilliseconds);
  }

  function formatDateInput(date) {
    return [
      date.getUTCFullYear(),
      String(date.getUTCMonth() + 1).padStart(2, '0'),
      String(date.getUTCDate()).padStart(2, '0'),
    ].join('-');
  }

  function dateOffsetForRentalPlan(plan) {
    if (plan === 'semanal') {
      return 7;
    }
    if (plan === 'mensual') {
      return 30;
    }

    return 1;
  }

  function syncRentalPeriodForPlan(form) {
    const selectedPlan = form.querySelector('input[name="rental_plan"]:checked');
    const startInput = form.elements.start_date;
    const endInput = form.elements.end_date;
    if (!selectedPlan || !startInput || !endInput) {
      return;
    }

    const start = parseDateInput(startInput.value) || parseDateInput(formatDateInput(new Date()));
    if (!start) {
      return;
    }
    const end = new Date(start.getTime() + (dateOffsetForRentalPlan(selectedPlan.value) * dayInMilliseconds));
    startInput.value = formatDateInput(start);
    startInput.min = formatDateInput(new Date());
    endInput.value = formatDateInput(end);
    endInput.min = formatDateInput(new Date(start.getTime() + dayInMilliseconds));
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

  function productAreaHtml(item) {
    const area = Number(item.area_m2 || 0);
    return area > 0 ? `<small>Superficie de losa: ${escapeHtml(compactNumber(area))} m²</small>` : '';
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

  function checkoutFreightQuote() {
    const box = document.querySelector('[data-freight-calculator]');
    if (!box) {
      return null;
    }
    const input = box.querySelector('[data-distance-km]');
    const truckSelect = box.querySelector('[data-truck-type]');
    const selectedTruck = truckSelect?.selectedOptions?.[0];
    const distance = Number(input?.value || 0);
    const config = {
      perKm: Number(selectedTruck?.dataset.costPerKm || 0),
      roundTripFactor: Number(box.dataset.roundTripFactor || 2),
    };
    const amount = freightAmount(distance, config);

    return {
      amount,
      distance,
      roundTripDistance: Number.isFinite(distance) ? distance * config.roundTripFactor : 0,
      perKm: config.perKm,
      truckSelected: Boolean(truckSelect?.value),
    };
  }

  function checkoutTotalHtml(cart) {
    const subtotal = cartTotal(cart);
    const quote = checkoutFreightQuote();
    const freight = quote?.amount ?? null;
    const routeText = freight !== null && quote?.truckSelected
      ? `Ruta Inexo a entrega: ${quote.distance.toFixed(2)} km ida · ${quote.roundTripDistance.toFixed(2)} km ida y vuelta · ${formatMoney(quote.perKm)}/km`
      : 'En el próximo paso del checkout podrás seleccionar direccion y tipo de camión para calcular el envío.';

    return `
      <div class="app-cart-total app-checkout-total" data-checkout-totals>
        <div class="app-checkout-total-row app-checkout-freight-row" style="align-items: center;">
          <img class="app-checkout-freight-icon" src="${escapeHtml(appUrl(truckIconPath))}" data-truck-icon data-fallback-src="${escapeHtml(truckIconPath)}" alt="" loading="lazy" aria-hidden="true" style="align-self: center;">
          <div class="app-checkout-freight-copy">
            <span>Flete ida y vuelta</span>
            <strong data-checkout-freight>${freight !== null && quote?.truckSelected ? formatMoney(freight) : 'por confirmar'}</strong>
            <small data-checkout-route>${escapeHtml(routeText)}</small>
          </div>
        </div>
        <div class="app-checkout-total-row app-checkout-subtotal-row" style="text-align: right;">
          <span>Subtotal equipos sin flete</span>
          <strong data-checkout-total>${formatMoney(subtotal)}</strong>
        </div>
      </div>
    `;
  }

  function cartOrderSummaryHtml(cart) {
    return `
      <div class="app-cart-order-footer">
        <div class="app-cart-total app-cart-order-summary">
          <div>
            <span>Subtotal equipos</span>
            <strong>${formatMoney(cartTotal(cart))}</strong>
          </div>
          <div class="app-cart-order-note">
            <svg class="app-cart-truck-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M3 6h11v9H3z"></path>
              <path d="M14 9h4l3 3v3h-7z"></path>
              <path d="M6.5 18a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
              <path d="M17.5 18a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"></path>
            </svg>
            <small>Este subtotal no incluye flete. En el próximo paso de cierre de pedido se calculará el envío ida y vuelta según dirección y tipo de camión.</small>
          </div>
        </div>
        <div class="app-cart-order-actions">
          <a href="${escapeHtml(appUrl('/checkout'))}" class="btn-contacto w-button">Hacer pedido</a>
        </div>
      </div>
    `;
  }

  function updateCheckoutTotals() {
    const target = document.querySelector('[data-checkout-totals]');
    if (!target) {
      return;
    }
    const cart = readCart();
    const subtotal = cartTotal(cart);
    const fulfillment = document.querySelector('[data-checkout-form] input[name="fulfillment_type"]:checked')?.value || '';
    const pickup = fulfillment === 'pickup';
    const quote = checkoutFreightQuote();
    const freight = quote?.amount ?? null;
    const freightNode = target.querySelector('[data-checkout-freight]');
    const routeNode = target.querySelector('[data-checkout-route]');
    const totalNode = target.querySelector('[data-checkout-total]');

    if (freightNode) {
      freightNode.textContent = pickup ? 'No aplica' : (freight !== null && quote?.truckSelected ? formatMoney(freight) : 'por confirmar');
    }
    if (routeNode) {
      routeNode.textContent = pickup
        ? 'Retiro coordinado en la oficina o deposito de Inexo.'
        : freight !== null && quote?.truckSelected
        ? `Ruta Inexo a entrega: ${quote.distance.toFixed(2)} km ida · ${quote.roundTripDistance.toFixed(2)} km ida y vuelta · ${formatMoney(quote.perKm)}/km`
        : 'Selecciona direccion, pin y tipo de camion para calcular el envio.';
    }
    if (totalNode) {
      totalNode.textContent = formatMoney(subtotal);
    }
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

  function cartDeliveryCity(cart = readCart()) {
    for (let index = cart.length - 1; index >= 0; index -= 1) {
      const city = String(cart[index]?.city || '').trim();
      if (city) {
        return city;
      }
    }

    return '';
  }

  function prefillCheckoutDeliveryCity(form) {
    const citySelect = form.querySelector('[data-delivery-city]');
    if (!citySelect || citySelect.value) {
      return;
    }

    const city = cartDeliveryCity();
    if (!city || !Array.from(citySelect.options).some((option) => option.value === city)) {
      return;
    }

    citySelect.value = city;
    citySelect.dataset.prefilledFromCart = '1';
    citySelect.dispatchEvent(new Event('change', { bubbles: true }));
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
            ${productAreaHtml(item)}
          </div>
          ${cartItemPriceHtml(item)}
          <button type="button" data-remove-cart="${index}">Quitar</button>
        </div>
      `;
    }).join('')}${cartOrderSummaryHtml(cart)}`;
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
            ${productAreaHtml(item)}
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

  document.addEventListener('error', (event) => {
    const image = event.target?.closest?.('[data-truck-icon]');
    if (!image) {
      return;
    }
    const fallbackSrc = image.dataset.fallbackSrc || '';
    if (!image.dataset.fallbackTried && fallbackSrc && image.getAttribute('src') !== fallbackSrc) {
      image.dataset.fallbackTried = '1';
      image.src = fallbackSrc;
      return;
    }
    image.hidden = true;
  }, true);

  document.querySelectorAll('[data-reservation-form]').forEach((form) => {
    syncRentalPeriodForPlan(form);
    form.querySelectorAll('input[name="rental_plan"]').forEach((input) => {
      input.addEventListener('change', () => syncRentalPeriodForPlan(form));
    });
    form.elements.start_date?.addEventListener('change', () => syncRentalPeriodForPlan(form));

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const submit = form.querySelector('.app-reserve-button');
      const message = form.querySelector('[data-form-message]');
      const data = Object.fromEntries(new FormData(form).entries());
      const rentalDays = rentalDayCountFromDates(data.start_date, data.end_date);
      if (!rentalDays) {
        if (message) {
          message.textContent = 'La fecha de fin debe ser posterior a la fecha de inicio.';
          message.style.color = '#a60000';
        }
        return;
      }
      const selectedPlan = form.querySelector('input[name="rental_plan"]:checked');
      const rentalUnits = rentalUnitsForPlan(data.rental_plan, rentalDays);
      if (submit?.dataset.requiresAreaM2 === '1' && Number(data.area_m2 || 0) <= 0) {
        if (message) {
          message.textContent = 'Indica para cuantos m² de losa necesitas los casetones.';
          message.style.color = '#a60000';
        }
        return;
      }

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
          area_m2: Number(data.area_m2 || 0),
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
    const logisticsFields = form.querySelector('[data-logistics-fields]');
    const fulfillmentInputs = form.querySelectorAll('input[name="fulfillment_type"]');
    const isDelivery = () => form.elements.fulfillment_type?.value === 'delivery';
    const syncFulfillment = () => {
      const delivery = isDelivery();
      logisticsFields?.classList.toggle('app-hidden', !delivery);
      logisticsFields?.querySelectorAll('input, select, textarea').forEach((field) => {
        field.disabled = !delivery;
      });
      form.querySelectorAll('[data-logistics-required]').forEach((field) => {
        field.required = delivery;
      });
      syncZoneVisibility();
      updateCheckoutTotals();
    };
    const syncZoneVisibility = () => {
      const needsZone = isDelivery() && citySelect?.value === 'Santo Domingo';
      zoneWrap?.classList.toggle('app-hidden', !needsZone);
      if (zoneInput) {
        zoneInput.required = Boolean(needsZone);
        if (!needsZone) {
          zoneInput.value = '';
        }
      }
    };
    citySelect?.addEventListener('change', syncZoneVisibility);
    fulfillmentInputs.forEach((input) => input.addEventListener('change', syncFulfillment));
    prefillCheckoutDeliveryCity(form);
    syncFulfillment();
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
      if (!form.reportValidity()) {
        if (message) {
          message.textContent = 'Revisa los campos obligatorios indicados antes de continuar.';
          message.style.color = '#a60000';
        }
        return;
      }
      if (isDelivery() && (!form.elements.delivery_lat?.value || !form.elements.delivery_lng?.value)) {
        if (message) {
          message.textContent = 'Selecciona un pin valido en el mapa para indicar la ubicacion de entrega.';
          message.style.color = '#a60000';
        }
        form.querySelector('[data-map-picker="delivery"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
      node.textContent = `${days}D-${hours}H-${minutes}M-${seconds}S`;
    });
  }

  function setupOfferPopup() {
    const popup = document.querySelector('[data-offer-popup]');
    if (!popup || sessionStorage.getItem('inexoOfferPopupClosed') === '1') {
      popup?.remove();
      return;
    }
    window.setTimeout(() => popup.classList.add('is-visible'), 500);
    popup.querySelector('[data-close-offer-popup]')?.addEventListener('click', () => {
      sessionStorage.setItem('inexoOfferPopupClosed', '1');
      popup.remove();
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
        const subtotal = cartTotal(readCart());
        const total = subtotal + (amount || 0);
        if (amount === null || !truckSelect?.value) {
          summary.innerHTML = `
            <span>Flete ida y vuelta</span>
            <strong>Por confirmar</strong>
            <small>Selecciona direccion y camion para calcular equipos + flete.</small>
          `;
        } else {
          summary.innerHTML = `
            <span>Flete ida y vuelta estimado</span>
            <strong>${formatMoney(amount)}</strong>
            <small>${(distance * config.roundTripFactor).toFixed(1)} km · ${formatMoney(config.perKm)}/km</small>
            <div class="app-freight-checkout-total">
              <span>Total equipos + flete</span>
              <strong>${formatMoney(total)}</strong>
            </div>
          `;
        }
        updateCheckoutTotals();
      };
      input?.addEventListener('input', render);
      truckSelect?.addEventListener('change', render);
      render();
    });
  }

  window.initInexoMaps = function initInexoMaps() {
    if (!window.google?.maps?.places) {
      return;
    }

    const defaultCenter = { lat: 18.7357, lng: -70.1627 };
    const googleMapsApiKey = String(window.INEXO_GOOGLE_MAPS_BROWSER_KEY || '').trim();
    const cityMapAreas = {
      'Santo Domingo': { center: { lat: 18.4861, lng: -69.9312 }, span: { lat: 0.28, lng: 0.38 }, zoom: 12 },
      'Santiago de los Caballeros': { center: { lat: 19.4517, lng: -70.6970 }, span: { lat: 0.22, lng: 0.28 }, zoom: 12 },
      'Punta Cana': { center: { lat: 18.5820, lng: -68.4055 }, span: { lat: 0.32, lng: 0.32 }, zoom: 12 },
      'La Romana': { center: { lat: 18.4273, lng: -68.9728 }, span: { lat: 0.20, lng: 0.24 }, zoom: 13 },
      'San Pedro de Macoris': { center: { lat: 18.4616, lng: -69.2972 }, span: { lat: 0.20, lng: 0.24 }, zoom: 13 },
      'San Francisco de Macoris': { center: { lat: 19.3000, lng: -70.2526 }, span: { lat: 0.20, lng: 0.24 }, zoom: 13 },
      'La Vega': { center: { lat: 19.2221, lng: -70.5296 }, span: { lat: 0.18, lng: 0.22 }, zoom: 13 },
      'Puerto Plata': { center: { lat: 19.7808, lng: -70.6871 }, span: { lat: 0.22, lng: 0.28 }, zoom: 12 },
      'Bonao': { center: { lat: 18.9369, lng: -70.4092 }, span: { lat: 0.18, lng: 0.22 }, zoom: 13 },
      'Moca': { center: { lat: 19.3935, lng: -70.5250 }, span: { lat: 0.18, lng: 0.22 }, zoom: 13 },
      'Bani': { center: { lat: 18.2796, lng: -70.3319 }, span: { lat: 0.18, lng: 0.22 }, zoom: 13 },
      'Azua': { center: { lat: 18.4532, lng: -70.7349 }, span: { lat: 0.18, lng: 0.22 }, zoom: 13 },
      'Barahona': { center: { lat: 18.2085, lng: -71.1008 }, span: { lat: 0.18, lng: 0.22 }, zoom: 13 },
      'Higuey': { center: { lat: 18.6150, lng: -68.7070 }, span: { lat: 0.20, lng: 0.24 }, zoom: 13 },
      'San Cristobal': { center: { lat: 18.4167, lng: -70.1056 }, span: { lat: 0.20, lng: 0.24 }, zoom: 13 },
    };
    const santoDomingoZoneMapAreas = {
      'Distrito Nacional': { center: { lat: 18.4861, lng: -69.9312 }, span: { lat: 0.12, lng: 0.16 }, zoom: 13 },
      'Santo Domingo Este': { center: { lat: 18.5030, lng: -69.8267 }, span: { lat: 0.16, lng: 0.18 }, zoom: 13 },
      'Santo Domingo Norte': { center: { lat: 18.5601, lng: -69.9027 }, span: { lat: 0.18, lng: 0.18 }, zoom: 13 },
      'Santo Domingo Oeste': { center: { lat: 18.5023, lng: -70.0130 }, span: { lat: 0.16, lng: 0.18 }, zoom: 13 },
      'Los Alcarrizos': { center: { lat: 18.5206, lng: -70.0209 }, span: { lat: 0.12, lng: 0.14 }, zoom: 13 },
      'Boca Chica': { center: { lat: 18.4539, lng: -69.6063 }, span: { lat: 0.12, lng: 0.16 }, zoom: 13 },
      'Pedro Brand': { center: { lat: 18.5608, lng: -70.2350 }, span: { lat: 0.16, lng: 0.18 }, zoom: 13 },
      'San Antonio de Guerra': { center: { lat: 18.5580, lng: -69.7000 }, span: { lat: 0.16, lng: 0.18 }, zoom: 13 },
    };

    const readLatLng = (latField, lngField) => {
      const lat = Number(latField?.value || '');
      const lng = Number(lngField?.value || '');
      return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
    };

    const normalizeLatLng = (location) => {
      if (!location) {
        return null;
      }
      const lat = typeof location.lat === 'function' ? location.lat() : Number(location.lat);
      const lng = typeof location.lng === 'function' ? location.lng() : Number(location.lng);
      return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
    };

    const boundsForArea = (area) => {
      if (!area) {
        return null;
      }
      return new google.maps.LatLngBounds(
        {
          lat: area.center.lat - (area.span.lat / 2),
          lng: area.center.lng - (area.span.lng / 2),
        },
        {
          lat: area.center.lat + (area.span.lat / 2),
          lng: area.center.lng + (area.span.lng / 2),
        }
      );
    };

    const deliveryAreaForSelection = (city, zone) => (
      city === 'Santo Domingo' && santoDomingoZoneMapAreas[zone]
        ? santoDomingoZoneMapAreas[zone]
        : cityMapAreas[city] || null
    );

    const deliveryBoundsForSelection = (city, zone) => boundsForArea(deliveryAreaForSelection(city, zone));

    const setDeliveryDistance = (form, km) => {
      if (!form?.elements.delivery_distance_km || !Number.isFinite(km) || km <= 0) {
        return;
      }
      form.elements.delivery_distance_km.value = km.toFixed(2);
      form.elements.delivery_distance_km.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const approximateRouteDistanceKm = (originLatLng, destinationLatLng) => {
      const origin = normalizeLatLng(originLatLng);
      const destination = normalizeLatLng(destinationLatLng);
      if (!origin || !destination) {
        return null;
      }
      const toRadians = (degrees) => degrees * (Math.PI / 180);
      const earthRadiusKm = 6371;
      const latDistance = toRadians(destination.lat - origin.lat);
      const lngDistance = toRadians(destination.lng - origin.lng);
      const a = Math.sin(latDistance / 2) ** 2
        + Math.cos(toRadians(origin.lat)) * Math.cos(toRadians(destination.lat))
        * Math.sin(lngDistance / 2) ** 2;
      const straightLineKm = earthRadiusKm * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

      return straightLineKm * 1.25;
    };

    const calculateDeliveryDistance = (freightBox, form, destination) => {
      const destinationLatLng = normalizeLatLng(destination);
      if (!destinationLatLng || !freightBox) {
        return;
      }

      const originLat = Number(freightBox.dataset.originLat || '');
      const originLng = Number(freightBox.dataset.originLng || '');
      const originLatLng = Number.isFinite(originLat) && Number.isFinite(originLng)
        ? { lat: originLat, lng: originLng }
        : null;
      const origin = originLatLng
        ? new google.maps.LatLng(originLat, originLng)
        : freightBox.dataset.originAddress;
      if (!origin) {
        return;
      }

      const fallbackDistance = () => {
        const fallbackKm = approximateRouteDistanceKm(originLatLng, destinationLatLng);
        if (fallbackKm) {
          setDeliveryDistance(form, fallbackKm);
        }
      };

      if (!window.google?.maps?.DistanceMatrixService) {
        fallbackDistance();
        return;
      }

      const service = new google.maps.DistanceMatrixService();
      service.getDistanceMatrix({
        origins: [origin],
        destinations: [new google.maps.LatLng(destinationLatLng.lat, destinationLatLng.lng)],
        travelMode: google.maps.TravelMode.DRIVING,
        unitSystem: google.maps.UnitSystem.METRIC,
      }, (response, status) => {
        const element = response?.rows?.[0]?.elements?.[0];
        if (status !== 'OK' || element?.status !== 'OK') {
          fallbackDistance();
          return;
        }
        setDeliveryDistance(form, element.distance.value / 1000);
      });
    };

    const setupAddressMapPicker = ({
      input,
      mapBox,
      placeIdField,
      latField,
      lngField,
      cityField,
      zoneField,
      onLocationChange,
      onLocationReset,
    }) => {
      if (!input) {
        return;
      }

      const canvas = mapBox?.querySelector('[data-map-canvas]');
      const status = mapBox?.querySelector('[data-map-status]');
      const coordinates = mapBox?.querySelector('[data-map-coordinates]');
      const initialPosition = readLatLng(latField, lngField);
      let map = null;
      let marker = null;
      let activeCity = cityField?.value || '';
      let activeZone = zoneField?.value || '';
      let activeCityBounds = deliveryBoundsForSelection(activeCity, activeZone);
      const geocoder = window.google?.maps?.Geocoder ? new google.maps.Geocoder() : null;

      if (canvas) {
        const initialArea = deliveryAreaForSelection(activeCity, activeZone);
        map = new google.maps.Map(canvas, {
          center: initialPosition || initialArea?.center || defaultCenter,
          zoom: initialPosition ? 16 : initialArea?.zoom || 8,
          mapTypeControl: false,
          streetViewControl: false,
          zoomControl: true,
        });
        marker = new google.maps.Marker({
          map,
          draggable: true,
          title: 'Ubicacion seleccionada',
          visible: Boolean(initialPosition),
        });
        if (initialPosition) {
          marker.setPosition(initialPosition);
        }
      }

      const setCoordinates = (position) => {
        if (!coordinates) {
          return;
        }
        coordinates.textContent = position
          ? `Coordenadas del pin: ${position.lat.toFixed(6)}, ${position.lng.toFixed(6)}`
          : 'Coordenadas del pin: por seleccionar';
      };

      const clearLocation = () => {
        if (placeIdField) {
          placeIdField.value = '';
        }
        if (latField) {
          latField.value = '';
        }
        if (lngField) {
          lngField.value = '';
        }
        if (marker) {
          marker.setVisible(false);
        }
        setCoordinates(null);
        if (onLocationReset) {
          onLocationReset();
        }
      };

      const setStatus = (position) => {
        setCoordinates(position);
        if (status && position) {
          status.textContent = `Pin seleccionado: ${position.lat.toFixed(6)}, ${position.lng.toFixed(6)}`;
        }
      };

      const setMessage = (message) => {
        if (status) {
          status.textContent = message;
        }
      };

      const setLocation = (location, options = {}) => {
        const position = normalizeLatLng(location);
        if (!position) {
          return false;
        }
        if (activeCityBounds && !activeCityBounds.contains(position)) {
          const areaLabel = activeZone ? `${activeZone}, ${activeCity}` : activeCity;
          setMessage(`Selecciona una direccion dentro de ${areaLabel}.`);
          if (marker && marker.getPosition()) {
            map?.panTo(marker.getPosition());
          } else {
            const area = deliveryAreaForSelection(activeCity, activeZone);
            if (area) {
              map?.panTo(area.center);
            }
          }
          return false;
        }

        if (latField) {
          latField.value = position.lat.toFixed(7);
        }
        if (lngField) {
          lngField.value = position.lng.toFixed(7);
        }
        if (options.clearPlaceId && placeIdField) {
          placeIdField.value = '';
        }
        if (map && marker) {
          marker.setPosition(position);
          marker.setVisible(true);
          map.panTo(position);
          if (map.getZoom() < 15) {
            map.setZoom(16);
          }
        }
        setStatus(position);
        if (onLocationChange) {
          onLocationChange(new google.maps.LatLng(position.lat, position.lng));
        }
        return true;
      };

      const geocodeComponent = (result, type) => (
        result?.address_components?.find((component) => component.types?.includes(type))?.long_name || ''
      );

      const formatGeocodedAddress = (result) => {
        if (!result) {
          return '';
        }
        const streetNumber = geocodeComponent(result, 'street_number');
        const route = geocodeComponent(result, 'route');
        const neighborhood = geocodeComponent(result, 'neighborhood')
          || geocodeComponent(result, 'sublocality')
          || geocodeComponent(result, 'sublocality_level_1');
        const city = geocodeComponent(result, 'locality')
          || geocodeComponent(result, 'administrative_area_level_2');
        const street = [route, streetNumber].filter(Boolean).join(' ');
        const compact = [street, neighborhood, city].filter(Boolean).join(', ');

        return compact || String(result.formatted_address || '').replace(/^[A-Z0-9+]{4,}\\s*,\\s*/i, '').trim();
      };

      const currentAreaLabel = () => (
        activeZone && activeCity ? `${activeZone}, ${activeCity}` : activeCity
      );

      const fallbackPinnedAddress = () => (
        currentAreaLabel() ? `Ubicacion marcada en ${currentAreaLabel()}` : 'Ubicacion marcada'
      );

      const addressResultScore = (result) => {
        const types = result?.types || [];
        const hasRoute = Boolean(geocodeComponent(result, 'route'));
        const hasStreetNumber = Boolean(geocodeComponent(result, 'street_number'));
        if (types.includes('street_address') && hasStreetNumber) {
          return 100;
        }
        if (hasRoute && hasStreetNumber) {
          return 90;
        }
        if (types.includes('intersection')) {
          return 80;
        }
        if (hasRoute) {
          return 70;
        }
        if (types.includes('premise')) {
          return 60;
        }
        if (result?.formatted_address && !result.formatted_address.includes('+')) {
          return 30;
        }
        return 0;
      };

      const bestAddressResult = (results = []) => (
        results
          .filter((result) => addressResultScore(result) > 0)
          .sort((a, b) => addressResultScore(b) - addressResultScore(a))[0] || null
      );

      const geocodePosition = (position) => new Promise((resolve) => {
        geocoder.geocode({
          location: position,
          bounds: activeCityBounds || undefined,
        }, (results, statusCode) => {
          resolve({
            results: Array.isArray(results) ? results : [],
            statusCode,
          });
        });
      });

      const nearestRoadPosition = async (position) => {
        if (!googleMapsApiKey || !window.fetch) {
          return null;
        }
        const params = new URLSearchParams({
          points: `${position.lat},${position.lng}`,
          key: googleMapsApiKey,
        });
        try {
          const response = await fetch(`https://roads.googleapis.com/v1/nearestRoads?${params.toString()}`);
          if (!response.ok) {
            return null;
          }
          const payload = await response.json();
          const snapped = payload?.snappedPoints?.[0]?.location;
          const lat = Number(snapped?.latitude);
          const lng = Number(snapped?.longitude);
          return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
        } catch (error) {
          return null;
        }
      };

      const nearbyGeocodePositions = (position) => {
        const latOffset = 0.00018;
        const lngOffset = latOffset / Math.max(Math.cos(position.lat * Math.PI / 180), 0.25);
        return [
          position,
          { lat: position.lat + latOffset, lng: position.lng },
          { lat: position.lat - latOffset, lng: position.lng },
          { lat: position.lat, lng: position.lng + lngOffset },
          { lat: position.lat, lng: position.lng - lngOffset },
          { lat: position.lat + (latOffset * 2), lng: position.lng },
          { lat: position.lat - (latOffset * 2), lng: position.lng },
          { lat: position.lat, lng: position.lng + (lngOffset * 2) },
          { lat: position.lat, lng: position.lng - (lngOffset * 2) },
        ];
      };

      const detectAddressNearPin = async (position) => {
        const allResults = [];
        let lastStatus = '';
        const directResponse = await geocodePosition(position);
        lastStatus = directResponse.statusCode || lastStatus;
        allResults.push(...directResponse.results);
        const directResult = bestAddressResult(directResponse.results);
        if (directResult && addressResultScore(directResult) >= 70) {
          return { result: directResult, statusCode: directResponse.statusCode };
        }

        const roadPosition = await nearestRoadPosition(position);
        if (roadPosition) {
          const roadResponse = await geocodePosition(roadPosition);
          lastStatus = roadResponse.statusCode || lastStatus;
          allResults.push(...roadResponse.results);
          const roadResult = bestAddressResult(roadResponse.results);
          if (roadResult && addressResultScore(roadResult) >= 70) {
            return { result: roadResult, statusCode: roadResponse.statusCode };
          }
        }

        for (const candidate of nearbyGeocodePositions(position).slice(1)) {
          const response = await geocodePosition(candidate);
          lastStatus = response.statusCode || lastStatus;
          allResults.push(...response.results);
          const candidateResult = bestAddressResult(response.results);
          if (candidateResult && addressResultScore(candidateResult) >= 70) {
            return { result: candidateResult, statusCode: response.statusCode };
          }
        }
        return { result: bestAddressResult(allResults), statusCode: lastStatus };
      };

      const updateAddressFromPin = async (location) => {
        const position = normalizeLatLng(location);
        if (!position || !setLocation(position, { clearPlaceId: true })) {
          return;
        }
        input.value = 'Buscando direccion...';
        setMessage('Buscando direccion del pin seleccionado...');
        if (!geocoder) {
          input.value = fallbackPinnedAddress();
          setMessage('Pin seleccionado. No se pudo detectar la direccion automaticamente; escribela o ajustala manualmente.');
          return;
        }

        const { result, statusCode } = await detectAddressNearPin(position);
        const detectedAddress = formatGeocodedAddress(result);
        if (!detectedAddress) {
          input.value = fallbackPinnedAddress();
          const suffix = statusCode && statusCode !== 'OK' ? ` (${statusCode})` : '';
          setMessage(`Pin seleccionado. No se encontro una calle cercana para este punto${suffix}; escribela o ajustala manualmente.`);
          return;
        }
        input.value = detectedAddress;
        if (placeIdField) {
          placeIdField.value = result.place_id || '';
        }
        setMessage(`Direccion detectada: ${input.value}`);
      };

      const autocomplete = new google.maps.places.Autocomplete(input, {
        fields: ['formatted_address', 'geometry', 'place_id'],
        componentRestrictions: { country: 'do' },
        bounds: activeCityBounds || undefined,
        strictBounds: Boolean(activeCityBounds),
      });

      const centerActiveArea = (force = false) => {
        const area = deliveryAreaForSelection(activeCity, activeZone);
        if (!map || !area) {
          return;
        }
        if (force || !marker?.getVisible()) {
          map.setCenter(area.center);
          map.setZoom(area.zoom);
        }
      };

      const applyCityRestriction = () => {
        activeCity = cityField?.value || '';
        activeZone = activeCity === 'Santo Domingo' ? zoneField?.value || '' : '';
        activeCityBounds = deliveryBoundsForSelection(activeCity, activeZone);
        if (activeCityBounds) {
          autocomplete.setBounds(activeCityBounds);
          autocomplete.setOptions({ strictBounds: true });
        } else {
          autocomplete.setOptions({ strictBounds: false });
        }
        centerActiveArea(!initialPosition);
        if (activeCity) {
          const areaLabel = activeZone ? `${activeZone}, ${activeCity}` : activeCity;
          setMessage(`Mapa centrado en ${areaLabel}. Busca una direccion o marca el pin dentro de la zona.`);
        }
      };

      autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (!place) {
          return;
        }
        if (place.geometry?.location && !setLocation(place.geometry.location)) {
          if (placeIdField) {
            placeIdField.value = '';
          }
          if (latField) {
            latField.value = '';
          }
          if (lngField) {
            lngField.value = '';
          }
          return;
        }
        if (place.formatted_address) {
          input.value = place.formatted_address;
        }
        if (placeIdField) {
          placeIdField.value = place.place_id || '';
        }
      });

      input.addEventListener('input', () => {
        if (placeIdField) {
          placeIdField.value = '';
        }
        if (!readLatLng(latField, lngField)) {
          clearLocation();
        }
      });

      if (cityField) {
        const updatePlaceholder = () => {
          const areaLabel = cityField.value === 'Santo Domingo' && zoneField?.value
            ? zoneField.value
            : cityField.value;
          input.placeholder = areaLabel
            ? `Direccion en ${areaLabel}`
            : 'Escribir ciudad, sector o direccion';
        };
        cityField.addEventListener('change', () => {
          updatePlaceholder();
          input.value = '';
          clearLocation();
          applyCityRestriction();
        });
        updatePlaceholder();
        applyCityRestriction();
        if (cityField.dataset.prefilledFromCart === '1') {
          window.requestAnimationFrame(() => {
            cityField.dispatchEvent(new Event('change', { bubbles: true }));
            delete cityField.dataset.prefilledFromCart;
          });
        }
        window.requestAnimationFrame(() => applyCityRestriction());
      }

      if (zoneField) {
        zoneField.addEventListener('change', () => {
          input.value = '';
          clearLocation();
          if (cityField) {
            input.placeholder = zoneField.value
              ? `Direccion en ${zoneField.value}`
              : `Direccion en ${cityField.value || 'Santo Domingo'}`;
          }
          applyCityRestriction();
        });
      }

      if (map) {
        map.addListener('click', (event) => {
          updateAddressFromPin(event.latLng);
        });
        google.maps.event.addListenerOnce(map, 'idle', () => {
          applyCityRestriction();
        });
      }
      if (marker) {
        marker.addListener('dragend', (event) => {
          updateAddressFromPin(event.latLng);
        });
      }

      if (initialPosition) {
        setStatus(initialPosition);
      } else {
        setCoordinates(null);
      }
    };

    const originInput = document.querySelector('[data-origin-address-input]');
    if (originInput) {
      const form = originInput.form;
      setupAddressMapPicker({
        input: originInput,
        mapBox: document.querySelector('[data-map-picker="origin"]'),
        placeIdField: form?.elements.company_origin_place_id,
        latField: form?.elements.company_origin_lat,
        lngField: form?.elements.company_origin_lng,
      });
    }

    const addressInput = document.querySelector('[data-delivery-address]');
    const freightBox = document.querySelector('[data-freight-calculator]');
    if (addressInput) {
      const form = addressInput.form;
      const clearDeliveryDistance = () => {
        if (form?.elements.delivery_distance_km) {
          form.elements.delivery_distance_km.value = '';
          form.elements.delivery_distance_km.dispatchEvent(new Event('input', { bubbles: true }));
        }
      };
      setupAddressMapPicker({
        input: addressInput,
        mapBox: document.querySelector('[data-map-picker="delivery"]'),
        placeIdField: form?.elements.delivery_place_id,
        latField: form?.elements.delivery_lat,
        lngField: form?.elements.delivery_lng,
        cityField: form?.elements.city,
        zoneField: form?.elements.delivery_zone,
        onLocationChange: (location) => calculateDeliveryDistance(freightBox, form, location),
        onLocationReset: clearDeliveryDistance,
      });
    }
  };

  updateCartCount();
  renderCartPage();
  renderCheckoutCart();
  setupFreightCalculators();
  setupOfferPopup();
  updateOfferCountdowns();
  window.setInterval(updateOfferCountdowns, 1000);
  fillProductRows();
  window.addEventListener('resize', scheduleProductRowFill);
})();
