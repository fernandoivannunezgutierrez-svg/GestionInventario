const state = {
  user: null,
  stores: [],
  activeStoreId: 'all', // 'all' or numeric string for admin; numeric for seller
  settings: {
    business_name: 'Mi Negocio Pyme',
    currency_symbol: '$',
    default_iva_rate: 19,
    default_other_tax_rate: 0,
    decimal_places: 0,
    cost_method: 'weighted_avg',
  },
  categories: [],
  products: [],
  cart: [],
  sellerCart: [],
};

// ==================== HELPERS ====================
async function api(url, options = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const data = await res.json();
  if (res.status === 401 && !url.includes('auth.php')) {
    showLoginScreen();
    throw new Error(data.error || 'Sesión expirada.');
  }
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Error HTTP ${res.status}`);
  }
  return data;
}

function fmtMoney(amount) {
  const n = Number(amount || 0);
  const dec = Number(state.settings.decimal_places ?? 0);
  const sym = state.settings.currency_symbol || '$';
  const sign = n < 0 ? '-' : '';
  const abs = Math.abs(n).toLocaleString('es-CL', {
    minimumFractionDigits: dec,
    maximumFractionDigits: dec,
  });
  return `${sign}${sym}${abs}`;
}

function fmtQty(qty, unit = '') {
  const n = Number(qty || 0);
  const formatted = Number.isInteger(n)
    ? n.toLocaleString('es-CL')
    : n.toLocaleString('es-CL', { minimumFractionDigits: 0, maximumFractionDigits: 3 });
  return unit ? `${formatted} ${unit}` : formatted;
}

function esc(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function toast(message, type = 'success') {
  const container = document.getElementById('toast-container');
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = message;
  container.appendChild(el);
  setTimeout(() => el.remove(), 3800);
}

function openModal(id) {
  document.getElementById(id).classList.add('open');
}

function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}

function getEffectiveStoreParam() {
  if (state.user?.role === 'seller') {
    return String(state.user.store_id || 1);
  }
  return state.activeStoreId || 'all';
}

function getConcreteStoreId() {
  if (state.user?.role === 'seller') {
    return Number(state.user.store_id || 1);
  }
  if (state.activeStoreId && state.activeStoreId !== 'all') {
    return Number(state.activeStoreId);
  }
  return state.stores[0]?.id ? Number(state.stores[0].id) : 1;
}

// ==================== AUTH & SHELLS ====================
function showLoginScreen() {
  state.user = null;
  document.getElementById('login-screen').style.display = 'flex';
  document.getElementById('admin-shell').style.display = 'none';
  document.getElementById('seller-shell').style.display = 'none';
}

async function enterApp(user, stores = []) {
  state.user = user;
  state.stores = stores;
  document.getElementById('login-screen').style.display = 'none';

  await loadSettings();

  if (user.role === 'admin') {
    document.getElementById('seller-shell').style.display = 'none';
    document.getElementById('admin-shell').style.display = 'flex';
    document.getElementById('admin-user-name').textContent = user.full_name;
    renderStoreSelectors();
    switchView('dashboard');
  } else {
    document.getElementById('admin-shell').style.display = 'none';
    document.getElementById('seller-shell').style.display = 'flex';
    document.getElementById('seller-business-name').textContent = state.settings.business_name;
    document.getElementById('seller-store-name').textContent = user.store_name || `Tienda ${user.store_id || 1}`;
    document.getElementById('seller-user-name').textContent = user.full_name;
    state.activeStoreId = String(user.store_id || 1);
    switchSellerView('pos');
  }
}

document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const username = document.getElementById('login-username').value.trim();
  const password = document.getElementById('login-password').value;
  try {
    const data = await api('api/auth.php', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    });
    document.getElementById('login-password').value = '';
    await enterApp(data.user, data.stores || []);
  } catch (err) {
    toast(err.message, 'error');
  }
});

document.getElementById('login-card-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const card_code = document.getElementById('login-card-code').value.trim();
  if (!card_code) return;
  try {
    const data = await api('api/auth.php', {
      method: 'POST',
      body: JSON.stringify({ card_code }),
    });
    document.getElementById('login-card-code').value = '';
    await enterApp(data.user, data.stores || []);
  } catch (err) {
    toast(err.message, 'error');
  }
});

async function logout() {
  await api('api/auth.php', { method: 'DELETE' });
  state.cart = [];
  state.sellerCart = [];
  showLoginScreen();
}

document.getElementById('btn-admin-logout').addEventListener('click', logout);
document.getElementById('btn-seller-logout').addEventListener('click', logout);

function renderStoreSelectors() {
  const adminSel = document.getElementById('admin-store-select');
  const cur = state.activeStoreId;
  adminSel.innerHTML =
    '<option value="all">🌐 Vista Global (Todas)</option>' +
    state.stores.map((s) => `<option value="${s.id}">🏪 ${esc(s.name)}</option>`).join('');
  adminSel.value = cur;

  const storeOpts = state.stores.map((s) => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
  ['sale-store-select', 'pur-store', 'adj-store', 'u-store'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) {
      el.innerHTML = storeOpts;
      if (state.activeStoreId !== 'all') {
        el.value = state.activeStoreId;
      }
    }
  });
}

document.getElementById('admin-store-select').addEventListener('change', (e) => {
  state.activeStoreId = e.target.value;
  renderStoreSelectors();
  const activeBtn = document.querySelector('.nav-btn.active');
  switchView(activeBtn ? activeBtn.dataset.view : 'dashboard');
});

// ==================== ADMIN NAVIGATION ====================
function switchView(viewName) {
  document.querySelectorAll('.nav-btn').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.view === viewName);
  });
  document.querySelectorAll('.view-section').forEach((sec) => {
    sec.classList.toggle('active', sec.id === `view-${viewName}`);
  });

  if (viewName === 'dashboard') loadDashboard();
  if (viewName === 'products') loadProducts();
  if (viewName === 'sales') {
    loadProducts().then(() => {
      renderSaleProductSelect();
      loadSalesHistory();
    });
  }
  if (viewName === 'purchases') {
    loadProducts().then(() => {
      renderPurchaseProductSelect();
      updatePurchasePreview();
      loadPurchasesHistory();
    });
  }
  if (viewName === 'movements') {
    loadProducts().then(() => {
      renderMovementProductFilter();
      loadMovements();
    });
  }
  if (viewName === 'stores-users') {
    loadStoresAndUsers();
  }
  if (viewName === 'settings') loadSettings();
}

document.querySelectorAll('.nav-btn').forEach((btn) => {
  btn.addEventListener('click', () => switchView(btn.dataset.view));
});

// ==================== INITIALIZATION ====================
async function initApp() {
  try {
    const auth = await api('api/auth.php');
    if (auth.user) {
      await enterApp(auth.user, auth.stores || []);
    } else {
      showLoginScreen();
    }
  } catch (err) {
    showLoginScreen();
    toast(err.message, 'error');
  }
}

async function loadSettings() {
  const data = await api('api/settings.php');
  state.settings = data.settings;
  state.categories = data.categories;

  document.getElementById('sidebar-business-name').textContent = state.settings.business_name;

  if (state.user?.role === 'admin') {
    document.getElementById('set-business-name').value = state.settings.business_name;
    document.getElementById('set-currency').value = state.settings.currency_symbol;
    document.getElementById('set-iva').value = state.settings.default_iva_rate;
    document.getElementById('set-other-tax').value = state.settings.default_other_tax_rate;
    document.getElementById('set-decimals').value = String(state.settings.decimal_places);
    document.getElementById('set-cost-method').value = state.settings.cost_method;
    renderCategories();
  }
}

function renderCategories() {
  const catList = document.getElementById('categories-list');
  const filterSel = document.getElementById('prod-cat-filter');
  const modalSel = document.getElementById('p-category');

  catList.innerHTML = state.categories
    .map(
      (c) =>
        `<span class="badge badge-gray" style="border-left:4px solid ${esc(c.color)}">${esc(c.name)}</span>`
    )
    .join('');

  const currentFilter = filterSel.value;
  filterSel.innerHTML =
    '<option value="">Todas las categorías</option>' +
    state.categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  filterSel.value = currentFilter;

  modalSel.innerHTML =
    '<option value="">Sin categoría</option>' +
    state.categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
}

// ==================== DASHBOARD ====================
async function loadDashboard() {
  const storeParam = getEffectiveStoreParam();
  const data = await api(`api/dashboard.php?store_id=${encodeURIComponent(storeParam)}`);
  const k = data.kpis;

  const activeStore = state.stores.find((s) => String(s.id) === String(storeParam));
  document.getElementById('dash-store-label').textContent =
    storeParam === 'all' ? 'Vista Global (Todas las tiendas)' : activeStore ? activeStore.name : `Tienda ${storeParam}`;

  document.getElementById('kpi-total-profit').textContent = fmtMoney(k.total_profit);
  document.getElementById('kpi-today-profit').textContent = fmtMoney(k.today_profit);
  document.getElementById('kpi-total-money').textContent = fmtMoney(k.total_money_movement);
  document.getElementById('kpi-sales-gross').textContent = fmtMoney(k.sales_gross);
  document.getElementById('kpi-purchases-total').textContent = fmtMoney(k.purchases_total);

  document.getElementById('kpi-inv-cost').textContent = fmtMoney(k.inventory_cost_value);
  document.getElementById('kpi-inv-sale').textContent = fmtMoney(k.inventory_sale_value);
  document.getElementById('kpi-inv-profit').textContent = fmtMoney(k.inventory_potential_profit);

  const totalTaxes = Number(k.total_iva) + Number(k.total_other_tax);
  document.getElementById('kpi-total-taxes').textContent = fmtMoney(totalTaxes);
  document.getElementById('kpi-total-products').textContent = k.total_products;
  document.getElementById('kpi-low-stock-text').textContent = `${k.low_stock_count} con stock bajo`;

  const badge = document.getElementById('nav-low-stock-badge');
  if (k.low_stock_count > 0) {
    badge.style.display = 'inline-block';
    badge.textContent = k.low_stock_count;
  } else {
    badge.style.display = 'none';
  }

  document.getElementById('btn-load-demo').style.display =
    k.total_products === 0 ? 'inline-flex' : 'none';

  // Stores comparison card (shown in global view or when >1 store)
  const storesCard = document.getElementById('dash-stores-card');
  const storesBody = document.getElementById('dash-stores-body');
  if (storeParam === 'all' && data.stores_summary) {
    storesCard.style.display = 'block';
    storesBody.innerHTML = data.stores_summary
      .map(
        (s) => `
      <tr>
        <td><strong>${esc(s.name)}</strong></td>
        <td class="text-right num">${fmtMoney(s.stock_cost_value)}</td>
        <td class="text-right num">${fmtMoney(s.purchases_total)}</td>
        <td class="text-right text-orange num">${fmtMoney(s.sales_gross)}</td>
        <td class="text-right text-green num">${fmtMoney(s.total_profit)}</td>
        <td class="text-right">
          <button class="btn btn-sm btn-outline" onclick="selectAdminStore(${s.id})">Entrar</button>
        </td>
      </tr>`
      )
      .join('');
  } else {
    storesCard.style.display = 'none';
  }

  // Low stock table
  const lowBody = document.getElementById('dash-low-stock-body');
  if (data.low_stock.length === 0) {
    lowBody.innerHTML = '<tr><td colspan="4" class="empty-state">Todo el stock está en niveles saludables.</td></tr>';
  } else {
    lowBody.innerHTML = data.low_stock
      .map(
        (p) => `
      <tr>
        <td><strong>${esc(p.name)}</strong></td>
        <td class="text-right text-danger num">${fmtQty(p.stock, p.unit_label)}</td>
        <td class="text-right num">${fmtQty(p.min_stock, p.unit_label)}</td>
        <td class="text-right">
          <button class="btn btn-sm btn-orange" onclick="quickBuy(${p.id})">Comprar</button>
        </td>
      </tr>`
      )
      .join('');
  }

  // Top products
  const topBody = document.getElementById('dash-top-products-body');
  if (data.top_products.length === 0) {
    topBody.innerHTML = '<tr><td colspan="4" class="empty-state">Aún no hay ventas registradas.</td></tr>';
  } else {
    topBody.innerHTML = data.top_products
      .map(
        (p) => `
      <tr>
        <td><strong>${esc(p.name)}</strong></td>
        <td class="text-right num">${fmtQty(p.qty_sold, p.unit_label)}</td>
        <td class="text-right text-orange num">${fmtMoney(p.gross_sold)}</td>
        <td class="text-right text-green num">${fmtMoney(p.profit_earned)}</td>
      </tr>`
      )
      .join('');
  }

  // Recent movements
  const recBody = document.getElementById('dash-recent-body');
  if (data.recent_movements.length === 0) {
    recBody.innerHTML = '<tr><td colspan="8" class="empty-state">Sin movimientos todavía.</td></tr>';
  } else {
    recBody.innerHTML = data.recent_movements.map(renderMovementRowCompact).join('');
  }
}

function selectAdminStore(storeId) {
  state.activeStoreId = String(storeId);
  document.getElementById('admin-store-select').value = state.activeStoreId;
  renderStoreSelectors();
  loadDashboard();
}

function movementTypeBadge(type) {
  const map = {
    purchase: '<span class="badge badge-blue">Compra (+)</span>',
    sale: '<span class="badge badge-orange">Venta (-)</span>',
    adjustment_in: '<span class="badge badge-green">Ajuste (+)</span>',
    adjustment_out: '<span class="badge badge-red">Merma / Salida (-)</span>',
  };
  return map[type] || esc(type);
}

function renderMovementRowCompact(m) {
  const sign = m.movement_type === 'purchase' || m.movement_type === 'adjustment_in' ? '+' : '-';
  const profitHtml =
    Number(m.profit_amount) !== 0
      ? `<span class="${Number(m.profit_amount) > 0 ? 'text-green' : 'text-danger'} num">${fmtMoney(m.profit_amount)}</span>`
      : '<span class="text-muted">—</span>';
  const moneyHtml =
    Number(m.money_movement) > 0
      ? `<span class="text-orange num">${fmtMoney(m.money_movement)}</span>`
      : '<span class="text-muted">—</span>';

  return `
    <tr>
      <td class="text-muted">${esc(m.created_at)}</td>
      <td><span class="badge badge-gray">${esc(m.store_name || 'Tienda 1')}</span></td>
      <td>${movementTypeBadge(m.movement_type)}</td>
      <td><strong>${esc(m.product_name)}</strong></td>
      <td class="text-right num">${sign}${fmtQty(m.quantity, m.unit_label)}</td>
      <td class="text-right num">${fmtQty(m.stock_after, m.unit_label)}</td>
      <td class="text-right">${moneyHtml}</td>
      <td class="text-right">${profitHtml}</td>
    </tr>`;
}

// ==================== PRODUCTS ====================
async function loadProducts() {
  const search = document.getElementById('prod-search')?.value || '';
  const catId = document.getElementById('prod-cat-filter')?.value || '';
  const params = new URLSearchParams();
  params.set('store_id', getEffectiveStoreParam());
  if (search) params.set('search', search);
  if (catId) params.set('category_id', catId);

  const data = await api(`api/products.php?${params.toString()}`);
  state.products = data.products;
  if (state.user?.role === 'admin') {
    renderProductsTable();
  }
}

function renderProductsTable() {
  const tbody = document.getElementById('products-table-body');
  if (!tbody) return;

  if (state.products.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="8" class="empty-state">No hay productos. Haz clic en "+ Nuevo Producto" para comenzar.</td></tr>';
    return;
  }

  tbody.innerHTML = state.products
    .map((p) => {
      const stockClass = p.is_low_stock ? 'text-danger' : '';
      return `
      <tr>
        <td>
          <strong>${esc(p.name)}</strong>
          ${p.sku ? `<div class="text-muted" style="font-size:11.5px;">SKU: ${esc(p.sku)}</div>` : ''}
        </td>
        <td>
          ${
            p.category_name
              ? `<span class="badge badge-gray" style="border-left:3px solid ${esc(p.category_color || '#64748b')}">${esc(p.category_name)}</span>`
              : '<span class="text-muted">—</span>'
          }
        </td>
        <td class="text-right num ${stockClass}">
          <strong>${fmtQty(p.stock, p.unit_label)}</strong>
          ${p.is_low_stock ? '<div style="font-size:11px;">Stock bajo</div>' : ''}
        </td>
        <td class="text-right num">${fmtMoney(p.stock_cost_value)}</td>
        <td class="text-right num">${fmtMoney(p.cost_price)}</td>
        <td class="text-right num">
          <strong class="text-orange">${fmtMoney(p.sale_price_final)}</strong>
          <div class="text-muted" style="font-size:11px;">Neto: ${fmtMoney(p.sale_price_net)} · IVA ${p.iva_rate}%${p.other_tax_rate > 0 ? ` + ${p.other_tax_rate}%` : ''}</div>
        </td>
        <td class="text-right num">
          <span class="text-green">${fmtMoney(p.unit_profit)}</span>
          <div class="text-muted" style="font-size:11px;">${p.margin_pct}% margen</div>
        </td>
        <td class="text-right" style="white-space:nowrap;">
          <button class="btn btn-sm btn-orange" onclick="quickBuy(${p.id})" title="Registrar compra">+</button>
          <button class="btn btn-sm btn-green" onclick="quickSell(${p.id})" title="Vender">$</button>
          <button class="btn btn-sm btn-outline" onclick="openEditProduct(${p.id})">Editar</button>
          <button class="btn btn-sm btn-danger-outline" onclick="deleteProduct(${p.id})">×</button>
        </td>
      </tr>`;
    })
    .join('');
}

document.getElementById('prod-search').addEventListener('input', () => loadProducts());
document.getElementById('prod-cat-filter').addEventListener('change', () => loadProducts());

// Product Modal & Live Pricing Calculator
let lastEditedPriceField = 'margin';

function updateProductCalculator() {
  const cost = Math.max(0, parseFloat(document.getElementById('p-cost').value) || 0);
  const iva = Math.max(0, parseFloat(document.getElementById('p-iva').value) || 0);
  const otherTax = Math.max(0, parseFloat(document.getElementById('p-other-tax').value) || 0);
  const stock = Math.max(0, parseFloat(document.getElementById('p-stock').value) || 0);
  const mult = 1 + (iva + otherTax) / 100;

  let marginPct = parseFloat(document.getElementById('p-margin-pct').value) || 0;
  let net = parseFloat(document.getElementById('p-sale-net').value) || 0;
  let final = parseFloat(document.getElementById('p-sale-final').value) || 0;

  if (lastEditedPriceField === 'margin') {
    net = Math.round(cost * (1 + marginPct / 100) * 100) / 100;
    final = Math.round(net * mult * 100) / 100;
    document.getElementById('p-sale-net').value = net;
    document.getElementById('p-sale-final').value = final;
  } else if (lastEditedPriceField === 'net') {
    final = Math.round(net * mult * 100) / 100;
    marginPct = cost > 0 ? Math.round(((net - cost) / cost) * 10000) / 100 : 0;
    document.getElementById('p-sale-final').value = final;
    document.getElementById('p-margin-pct').value = marginPct;
  } else {
    net = mult > 0 ? Math.round((final / mult) * 100) / 100 : final;
    marginPct = cost > 0 ? Math.round(((net - cost) / cost) * 10000) / 100 : 0;
    document.getElementById('p-sale-net').value = net;
    document.getElementById('p-margin-pct').value = marginPct;
  }

  const taxAmount = Math.max(0, final - net);
  const profit = net - cost;
  const displayMargin = cost > 0 ? ((profit / cost) * 100).toFixed(1) : '0.0';

  document.getElementById('p-calc-net').textContent = fmtMoney(net);
  document.getElementById('p-calc-taxes').textContent = fmtMoney(taxAmount);
  document.getElementById('p-calc-final').textContent = fmtMoney(final);
  document.getElementById('p-calc-profit').textContent = fmtMoney(profit);
  document.getElementById('p-calc-margin').textContent = `${displayMargin}%`;
  document.getElementById('p-calc-stock-val').textContent = fmtMoney(stock * cost);
}

document.getElementById('p-margin-pct').addEventListener('input', () => {
  lastEditedPriceField = 'margin';
  updateProductCalculator();
});
document.getElementById('p-sale-net').addEventListener('input', () => {
  lastEditedPriceField = 'net';
  updateProductCalculator();
});
document.getElementById('p-sale-final').addEventListener('input', () => {
  lastEditedPriceField = 'final';
  updateProductCalculator();
});
['p-cost', 'p-iva', 'p-other-tax', 'p-stock'].forEach((id) => {
  document.getElementById(id).addEventListener('input', updateProductCalculator);
});

document.getElementById('p-measure-type').addEventListener('change', (e) => {
  const defaults = { unit: 'unid.', weight: 'kg', volume: 'L', length: 'm', pack: 'caja' };
  document.getElementById('p-unit-label').value = defaults[e.target.value] || 'unid.';
});

document.getElementById('btn-new-product').addEventListener('click', () => {
  document.getElementById('modal-product-title').textContent = 'Nuevo Producto';
  document.getElementById('product-form').reset();
  document.getElementById('p-id').value = '';
  document.getElementById('p-stock-group').style.display = 'flex';
  document.getElementById('p-calc-stock-val-row').style.display = 'flex';
  document.getElementById('p-iva').value = state.settings.default_iva_rate;
  document.getElementById('p-other-tax').value = state.settings.default_other_tax_rate;
  document.getElementById('p-margin-pct').value = '0';
  document.getElementById('p-unit-label').value = 'unid.';
  lastEditedPriceField = 'margin';
  updateProductCalculator();
  openModal('modal-product');
});

function openEditProduct(id) {
  const p = state.products.find((x) => Number(x.id) === Number(id));
  if (!p) return;
  document.getElementById('modal-product-title').textContent = `Editar: ${p.name}`;
  document.getElementById('p-id').value = p.id;
  document.getElementById('p-name').value = p.name;
  document.getElementById('p-sku').value = p.sku || '';
  document.getElementById('p-category').value = p.category_id || '';
  document.getElementById('p-measure-type').value = p.measure_type;
  document.getElementById('p-unit-label').value = p.unit_label;
  document.getElementById('p-stock-group').style.display = 'none';
  document.getElementById('p-calc-stock-val-row').style.display = 'none';
  document.getElementById('p-min-stock').value = p.min_stock;
  document.getElementById('p-cost').value = p.cost_price;
  document.getElementById('p-iva').value = p.iva_rate;
  document.getElementById('p-other-tax').value = p.other_tax_rate;
  document.getElementById('p-sale-net').value = p.sale_price_net;
  document.getElementById('p-sale-final').value = p.sale_price_final;
  document.getElementById('p-margin-pct').value =
    p.cost_price > 0 ? Math.round(((p.sale_price_net - p.cost_price) / p.cost_price) * 10000) / 100 : 0;
  lastEditedPriceField = 'net';
  updateProductCalculator();
  openModal('modal-product');
}

document.getElementById('product-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = document.getElementById('p-id').value;
  const measureType = document.getElementById('p-measure-type').value;
  const payload = {
    store_id: getConcreteStoreId(),
    name: document.getElementById('p-name').value.trim(),
    sku: document.getElementById('p-sku').value.trim(),
    category_id: document.getElementById('p-category').value || null,
    measure_type: measureType,
    unit_label: document.getElementById('p-unit-label').value.trim(),
    allow_decimals: ['weight', 'volume', 'length'].includes(measureType),
    min_stock: parseFloat(document.getElementById('p-min-stock').value) || 0,
    cost_price: parseFloat(document.getElementById('p-cost').value) || 0,
    iva_rate: parseFloat(document.getElementById('p-iva').value) || 0,
    other_tax_rate: parseFloat(document.getElementById('p-other-tax').value) || 0,
    sale_price_net: parseFloat(document.getElementById('p-sale-net').value) || 0,
    sale_price_final: parseFloat(document.getElementById('p-sale-final').value) || 0,
  };

  try {
    if (id) {
      payload.id = Number(id);
      await api('api/products.php', { method: 'PUT', body: JSON.stringify(payload) });
      toast('Producto actualizado correctamente.');
    } else {
      payload.stock = parseFloat(document.getElementById('p-stock').value) || 0;
      await api('api/products.php', { method: 'POST', body: JSON.stringify(payload) });
      toast('Producto creado correctamente.');
    }
    closeModal('modal-product');
    await loadProducts();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
});

async function deleteProduct(id) {
  const p = state.products.find((x) => Number(x.id) === Number(id));
  if (!confirm(`¿Desactivar el producto "${p?.name || id}"?`)) return;
  try {
    await api(`api/products.php?id=${id}`, { method: 'DELETE' });
    toast('Producto eliminado del catálogo.');
    await loadProducts();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
}

// ==================== SALES (ADMIN) ====================
function renderSaleProductSelect() {
  const sel = document.getElementById('sale-product-select');
  sel.innerHTML =
    '<option value="">-- Selecciona un producto --</option>' +
    state.products
      .map(
        (p) =>
          `<option value="${p.id}">${esc(p.name)} — Stock: ${fmtQty(p.stock, p.unit_label)} · Final: ${fmtMoney(p.sale_price_final)}</option>`
      )
      .join('');
}

function quickSell(productId) {
  switchView('sales');
  setTimeout(() => {
    document.getElementById('sale-product-select').value = String(productId);
    document.getElementById('sale-qty-input').focus();
  }, 150);
}

document.getElementById('btn-add-to-cart').addEventListener('click', () => {
  const productId = Number(document.getElementById('sale-product-select').value);
  const qty = parseFloat(document.getElementById('sale-qty-input').value) || 0;
  if (!productId) {
    toast('Selecciona un producto.', 'error');
    return;
  }
  if (qty <= 0) {
    toast('La cantidad debe ser mayor a 0.', 'error');
    return;
  }
  const product = state.products.find((p) => Number(p.id) === productId);
  if (!product) return;

  const existing = state.cart.find((c) => c.product_id === productId);
  const totalRequested = (existing ? existing.quantity : 0) + qty;
  if (totalRequested > product.stock) {
    toast(`Stock insuficiente. Disponible: ${fmtQty(product.stock, product.unit_label)}`, 'error');
    return;
  }

  if (existing) {
    existing.quantity = Math.round((existing.quantity + qty) * 1000) / 1000;
  } else {
    state.cart.push({
      product_id: productId,
      name: product.name,
      unit_label: product.unit_label,
      stock: product.stock,
      quantity: qty,
      unit_cost: product.cost_price,
      iva_rate: product.iva_rate,
      other_tax_rate: product.other_tax_rate,
      unit_price_final: product.sale_price_final,
    });
  }
  renderCart();
});

function computeLineBreakdown(item) {
  const mult = 1 + (item.iva_rate + item.other_tax_rate) / 100;
  const lineGross = Math.round(item.quantity * item.unit_price_final * 100) / 100;
  const lineNet = mult > 0 ? Math.round((lineGross / mult) * 100) / 100 : lineGross;
  let lineIva = 0;
  let lineOtherTax = 0;
  if (item.other_tax_rate <= 0) {
    lineIva = item.iva_rate > 0 ? Math.round((lineGross - lineNet) * 100) / 100 : 0;
  } else {
    lineIva = Math.round(lineNet * (item.iva_rate / 100) * 100) / 100;
    lineOtherTax = Math.round((lineGross - lineNet - lineIva) * 100) / 100;
  }
  const lineCost = Math.round(item.quantity * item.unit_cost * 100) / 100;
  const lineProfit = Math.round((lineNet - lineCost) * 100) / 100;
  return { lineGross, lineIva, lineOtherTax, lineNet, lineCost, lineProfit };
}

function renderCart() {
  const tbody = document.getElementById('sale-cart-body');
  if (state.cart.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="6" class="empty-state">Agrega productos arriba para calcular la venta en tiempo real.</td></tr>';
  } else {
    tbody.innerHTML = state.cart
      .map((item, idx) => {
        const calc = computeLineBreakdown(item);
        return `
        <tr>
          <td>
            <strong>${esc(item.name)}</strong>
            <div class="text-muted" style="font-size:11px;">Stock: ${fmtQty(item.stock, item.unit_label)}</div>
          </td>
          <td class="text-right">
            <input type="number" class="form-control num" style="width:90px; display:inline-block; text-align:right;"
              step="any" min="0.001" max="${item.stock}" value="${item.quantity}"
              onchange="updateCartQty(${idx}, this.value)">
            <span class="text-muted" style="font-size:11.5px;">${esc(item.unit_label)}</span>
          </td>
          <td class="text-right">
            <input type="number" class="form-control num" style="width:105px; display:inline-block; text-align:right;"
              step="0.01" min="0" value="${item.unit_price_final}"
              onchange="updateCartPrice(${idx}, this.value)">
          </td>
          <td class="text-right text-orange num">${fmtMoney(calc.lineGross)}</td>
          <td class="text-right text-green num">${fmtMoney(calc.lineProfit)}</td>
          <td class="text-right">
            <button class="btn btn-sm btn-danger-outline" onclick="removeCartItem(${idx})">×</button>
          </td>
        </tr>`;
      })
      .join('');
  }

  let totGross = 0;
  let totIva = 0;
  let totOther = 0;
  let totNet = 0;
  let totCost = 0;
  let totProfit = 0;

  state.cart.forEach((item) => {
    const c = computeLineBreakdown(item);
    totGross += c.lineGross;
    totIva += c.lineIva;
    totOther += c.lineOtherTax;
    totNet += c.lineNet;
    totCost += c.lineCost;
    totProfit += c.lineProfit;
  });

  document.getElementById('sale-sum-gross').textContent = fmtMoney(totGross);
  document.getElementById('sale-sum-iva').textContent = fmtMoney(totIva);
  document.getElementById('sale-sum-other-tax').textContent = fmtMoney(totOther);
  document.getElementById('sale-sum-net').textContent = fmtMoney(totNet);
  document.getElementById('sale-sum-cost').textContent = fmtMoney(totCost);
  document.getElementById('sale-sum-profit').textContent = fmtMoney(totProfit);
}

function updateCartQty(idx, val) {
  const qty = parseFloat(val) || 0;
  if (qty <= 0) {
    removeCartItem(idx);
    return;
  }
  if (qty > state.cart[idx].stock) {
    toast(`Stock máximo disponible: ${fmtQty(state.cart[idx].stock, state.cart[idx].unit_label)}`, 'error');
    renderCart();
    return;
  }
  state.cart[idx].quantity = qty;
  renderCart();
}

function updateCartPrice(idx, val) {
  state.cart[idx].unit_price_final = Math.max(0, parseFloat(val) || 0);
  renderCart();
}

function removeCartItem(idx) {
  state.cart.splice(idx, 1);
  renderCart();
}

document.getElementById('btn-confirm-sale').addEventListener('click', async () => {
  if (state.cart.length === 0) {
    toast('Agrega al menos un producto a la venta.', 'error');
    return;
  }
  const payload = {
    store_id: Number(document.getElementById('sale-store-select').value || getConcreteStoreId()),
    payment_method: document.getElementById('sale-payment-method').value,
    customer_name: document.getElementById('sale-customer').value.trim(),
    notes: document.getElementById('sale-notes').value.trim(),
    items: state.cart.map((c) => ({
      product_id: c.product_id,
      quantity: c.quantity,
      unit_price_final: c.unit_price_final,
    })),
  };

  try {
    const res = await api('api/sales.php', { method: 'POST', body: JSON.stringify(payload) });
    toast(`Venta #${res.sale.id} registrada. Ganancia: ${fmtMoney(res.sale.total_profit)}`);
    state.cart = [];
    document.getElementById('sale-customer').value = '';
    document.getElementById('sale-notes').value = '';
    renderCart();
    await loadProducts();
    renderSaleProductSelect();
    loadSalesHistory();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
});

async function loadSalesHistory() {
  const data = await api(`api/sales.php?limit=30&store_id=${encodeURIComponent(getEffectiveStoreParam())}`);
  const tbody = document.getElementById('sales-history-body');
  if (data.sales.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state">Aún no hay ventas registradas.</td></tr>';
    return;
  }
  tbody.innerHTML = data.sales
    .map((s) => {
      const taxes = Number(s.total_iva) + Number(s.total_other_tax);
      const summary = (s.items || [])
        .map((i) => `${fmtQty(i.quantity, i.unit_label)} ${esc(i.product_name)}`)
        .join(', ');
      return `
      <tr>
        <td class="num">#${s.id}</td>
        <td class="text-muted">${esc(s.created_at)}</td>
        <td>
          <div><span class="badge badge-gray">${esc(s.store_name || 'Tienda 1')}</span></div>
          <div class="text-muted" style="font-size:11.5px;">${esc(s.seller_name || 'Admin')}</div>
        </td>
        <td>
          <strong>${esc(s.customer_name || 'Cliente general')}</strong>
          <div class="text-muted" style="font-size:11.5px;">${summary}</div>
        </td>
        <td class="text-right text-orange num">${fmtMoney(s.total_gross)}</td>
        <td class="text-right text-muted num">${fmtMoney(taxes)}</td>
        <td class="text-right text-muted num">${fmtMoney(s.total_cost)}</td>
        <td class="text-right text-green num">${fmtMoney(s.total_profit)}</td>
      </tr>`;
    })
    .join('');
}

// ==================== SELLER POS SHELL ====================
function switchSellerView(view) {
  document.querySelectorAll('.seller-nav-btn').forEach((b) => {
    b.classList.toggle('active', b.dataset.sellerView === view);
  });
  document.querySelectorAll('.seller-view').forEach((v) => {
    v.style.display = v.id === `seller-view-${view}` ? 'block' : 'none';
  });

  if (view === 'pos') {
    loadProducts().then(() => {
      renderSellerProductSelect();
      renderSellerCart();
      document.getElementById('seller-scan-input').focus();
    });
  }
  if (view === 'stock') {
    loadProducts().then(() => renderSellerStockTable());
  }
  if (view === 'mysales') {
    loadSellerSalesHistory();
  }
}

document.querySelectorAll('.seller-nav-btn').forEach((btn) => {
  btn.addEventListener('click', () => switchSellerView(btn.dataset.sellerView));
});

function renderSellerProductSelect() {
  const sel = document.getElementById('seller-product-select');
  sel.innerHTML =
    '<option value="">-- Selecciona un producto --</option>' +
    state.products
      .map(
        (p) =>
          `<option value="${p.id}">${esc(p.name)} — Stock: ${fmtQty(p.stock, p.unit_label)} · ${fmtMoney(p.sale_price_final)}</option>`
      )
      .join('');
}

function addProductToSellerCart(product, qty) {
  if (!product) return;
  if (qty <= 0) {
    toast('La cantidad debe ser mayor a 0.', 'error');
    return;
  }
  const existing = state.sellerCart.find((c) => c.product_id === product.id);
  const totalRequested = (existing ? existing.quantity : 0) + qty;
  if (totalRequested > product.stock) {
    toast(`Stock insuficiente. Disponible: ${fmtQty(product.stock, product.unit_label)}`, 'error');
    return;
  }
  if (existing) {
    existing.quantity = Math.round((existing.quantity + qty) * 1000) / 1000;
  } else {
    state.sellerCart.push({
      product_id: product.id,
      name: product.name,
      unit_label: product.unit_label,
      stock: product.stock,
      quantity: qty,
      unit_price_final: product.sale_price_final,
    });
  }
  renderSellerCart();
}

document.getElementById('btn-seller-add').addEventListener('click', () => {
  const pid = Number(document.getElementById('seller-product-select').value);
  const qty = parseFloat(document.getElementById('seller-qty-input').value) || 1;
  const p = state.products.find((x) => Number(x.id) === pid);
  if (!p) {
    toast('Selecciona un producto.', 'error');
    return;
  }
  addProductToSellerCart(p, qty);
});

document.getElementById('seller-scan-input').addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;
  e.preventDefault();
  const q = e.target.value.trim().toLowerCase();
  if (!q) return;
  const qty = parseFloat(document.getElementById('seller-qty-input').value) || 1;
  const match =
    state.products.find((p) => (p.sku || '').toLowerCase() === q) ||
    state.products.find((p) => p.name.toLowerCase().includes(q));
  if (!match) {
    toast(`No se encontró producto con "${e.target.value}"`, 'error');
    return;
  }
  addProductToSellerCart(match, qty);
  e.target.value = '';
  document.getElementById('seller-qty-input').value = '1';
});

function renderSellerCart() {
  const tbody = document.getElementById('seller-cart-body');
  if (state.sellerCart.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Escanea o agrega productos para cobrar.</td></tr>';
  } else {
    tbody.innerHTML = state.sellerCart
      .map(
        (item, idx) => `
      <tr>
        <td>
          <strong>${esc(item.name)}</strong>
          <div class="text-muted" style="font-size:11px;">Disponible: ${fmtQty(item.stock, item.unit_label)}</div>
        </td>
        <td class="text-right">
          <input type="number" class="form-control num" style="width:85px; display:inline-block; text-align:right;"
            step="any" min="0.001" max="${item.stock}" value="${item.quantity}"
            onchange="updateSellerCartQty(${idx}, this.value)">
          <span class="text-muted" style="font-size:11.5px;">${esc(item.unit_label)}</span>
        </td>
        <td class="text-right num">${fmtMoney(item.unit_price_final)}</td>
        <td class="text-right text-orange num">${fmtMoney(item.quantity * item.unit_price_final)}</td>
        <td class="text-right">
          <button class="btn btn-sm btn-danger-outline" onclick="removeSellerCartItem(${idx})">×</button>
        </td>
      </tr>`
      )
      .join('');
  }

  const total = state.sellerCart.reduce((acc, i) => acc + i.quantity * i.unit_price_final, 0);
  document.getElementById('seller-total-gross').textContent = fmtMoney(total);
  updateSellerChange();
}

function updateSellerCartQty(idx, val) {
  const qty = parseFloat(val) || 0;
  if (qty <= 0) {
    removeSellerCartItem(idx);
    return;
  }
  if (qty > state.sellerCart[idx].stock) {
    toast(`Stock máximo disponible: ${fmtQty(state.sellerCart[idx].stock, state.sellerCart[idx].unit_label)}`, 'error');
    renderSellerCart();
    return;
  }
  state.sellerCart[idx].quantity = qty;
  renderSellerCart();
}

function removeSellerCartItem(idx) {
  state.sellerCart.splice(idx, 1);
  renderSellerCart();
}

function updateSellerChange() {
  const payMethod = document.getElementById('seller-payment-method').value;
  const cashBox = document.getElementById('seller-cash-box');
  cashBox.style.display = payMethod === 'cash' ? 'block' : 'none';

  const total = state.sellerCart.reduce((acc, i) => acc + i.quantity * i.unit_price_final, 0);
  const received = parseFloat(document.getElementById('seller-cash-received').value) || 0;
  const change = received >= total && total > 0 ? received - total : 0;
  document.getElementById('seller-cash-change').textContent = fmtMoney(change);
}

document.getElementById('seller-payment-method').addEventListener('change', updateSellerChange);
document.getElementById('seller-cash-received').addEventListener('input', updateSellerChange);

document.getElementById('btn-seller-confirm').addEventListener('click', async () => {
  if (state.sellerCart.length === 0) {
    toast('Agrega al menos un producto.', 'error');
    return;
  }
  const payMethod = document.getElementById('seller-payment-method').value;
  const cashRecVal = document.getElementById('seller-cash-received').value;
  const payload = {
    payment_method: payMethod,
    cash_received: payMethod === 'cash' && cashRecVal !== '' ? parseFloat(cashRecVal) : null,
    notes: document.getElementById('seller-notes').value.trim(),
    items: state.sellerCart.map((c) => ({ product_id: c.product_id, quantity: c.quantity })),
  };

  try {
    const res = await api('api/sales.php', { method: 'POST', body: JSON.stringify(payload) });
    const changeMsg = res.sale.cash_change !== null ? ` · Vuelto: ${fmtMoney(res.sale.cash_change)}` : '';
    toast(`Venta #${res.sale.id} cobrada (${fmtMoney(res.sale.total_gross)})${changeMsg}`);
    state.sellerCart = [];
    document.getElementById('seller-cash-received').value = '';
    document.getElementById('seller-notes').value = '';
    await loadProducts();
    renderSellerProductSelect();
    renderSellerCart();
  } catch (err) {
    toast(err.message, 'error');
  }
});

function renderSellerStockTable() {
  const q = (document.getElementById('seller-stock-search').value || '').trim().toLowerCase();
  const filtered = q
    ? state.products.filter(
        (p) => p.name.toLowerCase().includes(q) || (p.sku || '').toLowerCase().includes(q)
      )
    : state.products;

  const tbody = document.getElementById('seller-stock-body');
  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-state">No hay productos que coincidan.</td></tr>';
    return;
  }
  tbody.innerHTML = filtered
    .map(
      (p) => `
    <tr>
      <td><strong>${esc(p.name)}</strong>${p.sku ? `<div class="text-muted" style="font-size:11.5px;">SKU: ${esc(p.sku)}</div>` : ''}</td>
      <td>${esc(p.category_name || '—')}</td>
      <td class="text-right num ${p.is_low_stock ? 'text-danger' : ''}"><strong>${fmtQty(p.stock, p.unit_label)}</strong></td>
      <td class="text-right text-orange num">${fmtMoney(p.sale_price_final)}</td>
    </tr>`
    )
    .join('');
}

document.getElementById('seller-stock-search').addEventListener('input', renderSellerStockTable);

async function loadSellerSalesHistory() {
  const data = await api('api/sales.php?limit=40');
  const tbody = document.getElementById('seller-sales-body');
  if (data.sales.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="empty-state">Aún no registras ventas.</td></tr>';
    return;
  }
  const payLabels = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia', mixed: 'Mixto' };
  tbody.innerHTML = data.sales
    .map((s) => {
      const summary = (s.items || [])
        .map((i) => `${fmtQty(i.quantity, i.unit_label)} ${esc(i.product_name)}`)
        .join(', ');
      return `
      <tr>
        <td class="num">#${s.id}</td>
        <td class="text-muted">${esc(s.created_at)}</td>
        <td>${summary}</td>
        <td><span class="badge badge-gray">${payLabels[s.payment_method] || s.payment_method}</span></td>
        <td class="text-right text-orange num">${fmtMoney(s.total_gross)}</td>
        <td class="text-right text-green num">${s.cash_change !== null ? fmtMoney(s.cash_change) : '—'}</td>
      </tr>`;
    })
    .join('');
}

// ==================== PURCHASES ====================
function renderPurchaseProductSelect() {
  const sel = document.getElementById('pur-product');
  sel.innerHTML =
    '<option value="">-- Selecciona un producto --</option>' +
    state.products
      .map(
        (p) =>
          `<option value="${p.id}">${esc(p.name)} (Stock: ${fmtQty(p.stock, p.unit_label)} · Costo actual: ${fmtMoney(p.cost_price)})</option>`
      )
      .join('');
}

function quickBuy(productId) {
  switchView('purchases');
  setTimeout(() => {
    const sel = document.getElementById('pur-product');
    sel.value = String(productId);
    const p = state.products.find((x) => Number(x.id) === Number(productId));
    if (p) {
      document.getElementById('pur-cost').value = p.cost_price;
      document.getElementById('pur-unit-label').textContent = p.unit_label;
    }
    updatePurchasePreview();
    document.getElementById('pur-qty').focus();
  }, 150);
}

function updatePurchasePreview() {
  const pid = Number(document.getElementById('pur-product').value);
  const p = state.products.find((x) => Number(x.id) === pid);
  const qty = Math.max(0, parseFloat(document.getElementById('pur-qty').value) || 0);
  const cost = Math.max(0, parseFloat(document.getElementById('pur-cost').value) || 0);

  document.getElementById('pur-unit-label').textContent = p ? p.unit_label : 'unid.';
  document.getElementById('pur-calc-total').textContent = fmtMoney(qty * cost);

  if (p) {
    const stockAfter = p.stock + qty;
    let newAvgCost = cost;
    if (state.settings.cost_method === 'weighted_avg' && stockAfter > 0) {
      newAvgCost = (p.stock * p.cost_price + qty * cost) / stockAfter;
    }
    document.getElementById('pur-calc-stock-before').textContent = fmtQty(p.stock, p.unit_label);
    document.getElementById('pur-calc-stock-after').textContent = fmtQty(stockAfter, p.unit_label);
    document.getElementById('pur-calc-stock-value').textContent = fmtMoney(stockAfter * newAvgCost);
  } else {
    document.getElementById('pur-calc-stock-before').textContent = '0';
    document.getElementById('pur-calc-stock-after').textContent = fmtQty(qty);
    document.getElementById('pur-calc-stock-value').textContent = fmtMoney(qty * cost);
  }
}

document.getElementById('pur-product').addEventListener('change', () => {
  const pid = Number(document.getElementById('pur-product').value);
  const p = state.products.find((x) => Number(x.id) === pid);
  if (p) {
    document.getElementById('pur-cost').value = p.cost_price;
  }
  updatePurchasePreview();
});
document.getElementById('pur-qty').addEventListener('input', updatePurchasePreview);
document.getElementById('pur-cost').addEventListener('input', updatePurchasePreview);

document.getElementById('purchase-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    store_id: Number(document.getElementById('pur-store').value || getConcreteStoreId()),
    product_id: Number(document.getElementById('pur-product').value),
    quantity: parseFloat(document.getElementById('pur-qty').value) || 0,
    unit_cost: parseFloat(document.getElementById('pur-cost').value) || 0,
    supplier: document.getElementById('pur-supplier').value.trim(),
    notes: document.getElementById('pur-notes').value.trim(),
  };

  try {
    const res = await api('api/purchases.php', { method: 'POST', body: JSON.stringify(payload) });
    toast(`Compra registrada. Inversión: ${fmtMoney(res.money_movement)}`);
    document.getElementById('pur-qty').value = 1;
    document.getElementById('pur-supplier').value = '';
    document.getElementById('pur-notes').value = '';
    await loadProducts();
    renderPurchaseProductSelect();
    updatePurchasePreview();
    loadPurchasesHistory();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
});

async function loadPurchasesHistory() {
  const data = await api(`api/purchases.php?limit=30&store_id=${encodeURIComponent(getEffectiveStoreParam())}`);
  const tbody = document.getElementById('purchases-history-body');
  if (data.purchases.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty-state">Aún no hay compras registradas.</td></tr>';
    return;
  }
  tbody.innerHTML = data.purchases
    .map(
      (m) => `
      <tr>
        <td class="text-muted">${esc(m.created_at)}</td>
        <td><span class="badge badge-gray">${esc(m.store_name || 'Tienda 1')}</span></td>
        <td><strong>${esc(m.product_name)}</strong></td>
        <td class="text-right num">+${fmtQty(m.quantity, m.unit_label)}</td>
        <td class="text-right num">${fmtMoney(m.unit_cost)}</td>
        <td class="text-right text-orange num">${fmtMoney(m.money_movement)}</td>
        <td class="text-right num">${fmtQty(m.stock_after, m.unit_label)}</td>
        <td>${esc(m.counterparty || '—')} ${m.notes ? `· <span class="text-muted">${esc(m.notes)}</span>` : ''}</td>
      </tr>`
    )
    .join('');
}

// ==================== MOVEMENTS / KARDEX ====================
function renderMovementProductFilter() {
  const sel = document.getElementById('mov-filter-product');
  const cur = sel.value;
  sel.innerHTML =
    '<option value="">Todos los productos</option>' +
    state.products.map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
  sel.value = cur;

  const adjSel = document.getElementById('adj-product');
  adjSel.innerHTML = state.products
    .map((p) => `<option value="${p.id}">${esc(p.name)} (Stock: ${fmtQty(p.stock, p.unit_label)})</option>`)
    .join('');
}

function getMovementQueryParams() {
  const params = new URLSearchParams();
  params.set('store_id', getEffectiveStoreParam());
  const pid = document.getElementById('mov-filter-product').value;
  const type = document.getElementById('mov-filter-type').value;
  const from = document.getElementById('mov-filter-from').value;
  const to = document.getElementById('mov-filter-to').value;
  if (pid) params.set('product_id', pid);
  if (type) params.set('type', type);
  if (from) params.set('from', from);
  if (to) params.set('to', to);
  return params;
}

async function loadMovements() {
  const params = getMovementQueryParams();
  const data = await api(`api/movements.php?${params.toString()}`);
  const tbody = document.getElementById('movements-table-body');
  if (data.movements.length === 0) {
    tbody.innerHTML = '<tr><td colspan="10" class="empty-state">No hay movimientos para los filtros seleccionados.</td></tr>';
    return;
  }
  tbody.innerHTML = data.movements
    .map((m) => {
      const sign = m.movement_type === 'purchase' || m.movement_type === 'adjustment_in' ? '+' : '-';
      const taxes = Number(m.iva_amount) + Number(m.other_tax_amount);
      const profitHtml =
        Number(m.profit_amount) !== 0
          ? `<span class="${Number(m.profit_amount) > 0 ? 'text-green' : 'text-danger'} num">${fmtMoney(m.profit_amount)}</span>`
          : '<span class="text-muted">—</span>';
      const moneyHtml =
        Number(m.money_movement) > 0
          ? `<span class="text-orange num">${fmtMoney(m.money_movement)}</span>`
          : '<span class="text-muted">—</span>';

      return `
      <tr>
        <td class="text-muted">${esc(m.created_at)}</td>
        <td><span class="badge badge-gray">${esc(m.store_name || 'Tienda 1')}</span></td>
        <td>${movementTypeBadge(m.movement_type)}</td>
        <td><strong>${esc(m.product_name)}</strong></td>
        <td class="text-right num">${sign}${fmtQty(m.quantity, m.unit_label)}</td>
        <td class="text-right num">${fmtQty(m.stock_before)} → <strong>${fmtQty(m.stock_after, m.unit_label)}</strong></td>
        <td class="text-right">${moneyHtml}</td>
        <td class="text-right text-muted num">${taxes > 0 ? fmtMoney(taxes) : '—'}</td>
        <td class="text-right">${profitHtml}</td>
        <td>${esc(m.counterparty || '')} ${m.notes ? `<span class="text-muted">${esc(m.notes)}</span>` : ''}</td>
      </tr>`;
    })
    .join('');
}

document.getElementById('btn-filter-movements').addEventListener('click', loadMovements);
document.getElementById('btn-export-csv').addEventListener('click', () => {
  const params = getMovementQueryParams();
  params.set('export', 'csv');
  window.location.href = `api/movements.php?${params.toString()}`;
});

document.getElementById('btn-open-adjustment').addEventListener('click', () => {
  renderMovementProductFilter();
  openModal('modal-adjustment');
});

document.getElementById('adjustment-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    store_id: Number(document.getElementById('adj-store').value || getConcreteStoreId()),
    product_id: Number(document.getElementById('adj-product').value),
    movement_type: document.getElementById('adj-type').value,
    quantity: parseFloat(document.getElementById('adj-qty').value) || 0,
    notes: document.getElementById('adj-notes').value.trim(),
  };
  try {
    await api('api/movements.php', { method: 'POST', body: JSON.stringify(payload) });
    toast('Ajuste de inventario aplicado.');
    closeModal('modal-adjustment');
    await loadProducts();
    loadMovements();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
});

// ==================== STORES & USERS ====================
async function loadStoresAndUsers() {
  const [stData, uData] = await Promise.all([
    api('api/stores.php'),
    api(`api/users.php?store_id=${encodeURIComponent(getEffectiveStoreParam())}`),
  ]);
  state.stores = stData.stores;
  renderStoreSelectors();

  const storesBody = document.getElementById('stores-table-body');
  storesBody.innerHTML = stData.stores
    .map(
      (s) => `
    <tr>
      <td><strong>${esc(s.name)}</strong></td>
      <td class="text-muted">${esc(s.address || '—')} ${s.phone ? `· ${esc(s.phone)}` : ''}</td>
      <td class="text-right num">${s.sellers_count}</td>
      <td class="text-right num">${fmtMoney(s.stock_cost_value)}</td>
      <td class="text-right text-orange num">${fmtMoney(s.total_sales_gross)}</td>
      <td class="text-right text-green num">${fmtMoney(s.total_profit)}</td>
    </tr>`
    )
    .join('');

  const usersBody = document.getElementById('users-table-body');
  usersBody.innerHTML = uData.users
    .map(
      (u) => `
    <tr>
      <td>
        <strong>${esc(u.full_name)}</strong>
        <div class="text-muted" style="font-size:11.5px;">@${esc(u.username)} ${!u.is_active ? '· <span class="text-danger">Inactivo</span>' : ''}</div>
      </td>
      <td>${u.role === 'admin' ? '<span class="badge badge-blue">Dueño / Admin</span>' : '<span class="badge badge-gray">Vendedor</span>'}</td>
      <td>${esc(u.store_name || (u.role === 'admin' ? 'Todas las tiendas' : '—'))}</td>
      <td><code>${esc(u.card_code || '—')}</code></td>
      <td class="text-right num">${u.today_sales_count}</td>
      <td class="text-right text-orange num">${fmtMoney(u.today_sales_gross)}</td>
      <td class="text-right text-green num">${fmtMoney(u.today_sales_profit)}</td>
      <td class="text-right">
        <button class="btn btn-sm btn-outline" onclick='openEditUser(${JSON.stringify(u)})'>Editar</button>
      </td>
    </tr>`
    )
    .join('');
}

document.getElementById('btn-new-store').addEventListener('click', () => {
  document.getElementById('store-form').reset();
  openModal('modal-store');
});

document.getElementById('store-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const res = await api('api/stores.php', {
      method: 'POST',
      body: JSON.stringify({
        name: document.getElementById('st-name').value.trim(),
        address: document.getElementById('st-address').value.trim(),
        phone: document.getElementById('st-phone').value.trim(),
      }),
    });
    state.stores = res.stores;
    renderStoreSelectors();
    closeModal('modal-store');
    toast('Tienda creada correctamente.');
    loadStoresAndUsers();
  } catch (err) {
    toast(err.message, 'error');
  }
});

document.getElementById('btn-new-user').addEventListener('click', () => {
  document.getElementById('modal-user-title').textContent = 'Nuevo Vendedor / Usuario';
  document.getElementById('user-form').reset();
  document.getElementById('u-id').value = '';
  document.getElementById('u-username').disabled = false;
  document.getElementById('u-pass-hint').textContent = '(obligatoria)';
  document.getElementById('u-password').required = true;
  openModal('modal-user');
});

function openEditUser(u) {
  document.getElementById('modal-user-title').textContent = `Editar: ${u.full_name}`;
  document.getElementById('u-id').value = u.id;
  document.getElementById('u-full-name').value = u.full_name;
  document.getElementById('u-username').value = u.username;
  document.getElementById('u-username').disabled = true;
  document.getElementById('u-role').value = u.role;
  document.getElementById('u-store').value = u.store_id || state.stores[0]?.id || 1;
  document.getElementById('u-card-code').value = u.card_code || '';
  document.getElementById('u-password').value = '';
  document.getElementById('u-password').required = false;
  document.getElementById('u-pass-hint').textContent = '(dejar vacío para mantener)';
  openModal('modal-user');
}

document.getElementById('user-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = document.getElementById('u-id').value;
  const payload = {
    full_name: document.getElementById('u-full-name').value.trim(),
    username: document.getElementById('u-username').value.trim(),
    role: document.getElementById('u-role').value,
    store_id: Number(document.getElementById('u-store').value),
    card_code: document.getElementById('u-card-code').value.trim(),
    password: document.getElementById('u-password').value,
  };
  try {
    if (id) {
      payload.id = Number(id);
      await api('api/users.php', { method: 'PUT', body: JSON.stringify(payload) });
      toast('Usuario actualizado.');
    } else {
      await api('api/users.php', { method: 'POST', body: JSON.stringify(payload) });
      toast('Usuario creado correctamente.');
    }
    closeModal('modal-user');
    loadStoresAndUsers();
  } catch (err) {
    toast(err.message, 'error');
  }
});

// ==================== SETTINGS ====================
document.getElementById('settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    business_name: document.getElementById('set-business-name').value.trim(),
    currency_symbol: document.getElementById('set-currency').value.trim(),
    default_iva_rate: parseFloat(document.getElementById('set-iva').value) || 0,
    default_other_tax_rate: parseFloat(document.getElementById('set-other-tax').value) || 0,
    decimal_places: Number(document.getElementById('set-decimals').value),
    cost_method: document.getElementById('set-cost-method').value,
  };
  try {
    await api('api/settings.php', { method: 'PUT', body: JSON.stringify(payload) });
    toast('Configuración guardada.');
    await loadSettings();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
});

document.getElementById('category-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const name = document.getElementById('new-cat-name').value.trim();
  const color = document.getElementById('new-cat-color').value;
  try {
    const data = await api('api/settings.php', {
      method: 'POST',
      body: JSON.stringify({ name, color }),
    });
    state.categories = data.categories;
    document.getElementById('new-cat-name').value = '';
    renderCategories();
    toast('Categoría agregada.');
  } catch (err) {
    toast(err.message, 'error');
  }
});

// ==================== DEMO EXAMPLE (LÁPIZ MINA) ====================
document.getElementById('btn-load-demo').addEventListener('click', async () => {
  try {
    const papeleria = state.categories.find((c) => c.name.includes('Papelería'));
    const storeId = getConcreteStoreId();
    const res = await api('api/products.php', {
      method: 'POST',
      body: JSON.stringify({
        store_id: storeId,
        sku: 'LAP-MINA-01',
        name: 'Lápiz Mina HB',
        category_id: papeleria ? papeleria.id : null,
        measure_type: 'unit',
        unit_label: 'lápices',
        allow_decimals: false,
        stock: 100,
        min_stock: 15,
        cost_price: 100,
        iva_rate: 19,
        other_tax_rate: 0,
        sale_price_net: 150,
      }),
    });

    await api('api/sales.php', {
      method: 'POST',
      body: JSON.stringify({
        store_id: storeId,
        customer_name: 'Ejemplo Pyme',
        notes: 'Venta de ejemplo: 20 lápices mina',
        items: [{ product_id: res.product.id, quantity: 20 }],
      }),
    });

    toast('Ejemplo "Lápiz Mina" cargado (100 comprados, 20 vendidos).');
    await loadProducts();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  }
});

// ==================== IMPORTAR CSV ====================
document.getElementById('btn-import-csv').addEventListener('click', () => {
  document.getElementById('csv-file-input').click();
});

document.getElementById('csv-file-input').addEventListener('change', async (e) => {
  const file = e.target.files?.[0];
  if (!file) return;

  const formData = new FormData();
  formData.append('file', file);
  formData.append('store_id', String(getConcreteStoreId()));

  try {
    const res = await fetch('api/import_csv.php', {
      method: 'POST',
      body: formData,
    });
    const data = await res.json();
    if (!res.ok || data.ok === false) {
      throw new Error(data.error || `Error HTTP ${res.status}`);
    }
    toast(`CSV importado: ${data.created} creados, ${data.updated} actualizados.`);
    await loadSettings();
    await loadProducts();
    loadDashboard();
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    e.target.value = '';
  }
});

initApp();
