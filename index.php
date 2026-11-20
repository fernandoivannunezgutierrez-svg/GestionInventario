<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestión de Inventario Pyme — Multi-Tienda &amp; POS</title>
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>

  <!-- ==================== LOGIN SCREEN ==================== -->
  <div id="login-screen" class="login-wrapper" style="display:none;">
    <div class="login-card">
      <div class="login-header">
        <div class="login-logo">GI</div>
        <h1 style="font-size:20px; font-weight:800;">Gestión de Inventario Pyme</h1>
        <p class="text-muted" style="font-size:13px;">Inicia sesión como Dueño/Administrador o Vendedor</p>
      </div>

      <form id="login-form">
        <div class="form-group" style="margin-bottom:12px;">
          <label for="login-username">Usuario</label>
          <input type="text" id="login-username" class="form-control" placeholder="Ej: admin o vendedor1" autocomplete="username">
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label for="login-password">Contraseña / PIN</label>
          <input type="password" id="login-password" class="form-control" placeholder="••••••••" autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%; padding:11px; font-size:14.5px;">
          Ingresar al Sistema
        </button>
      </form>

      <div style="margin:16px 0; text-align:center; font-size:12px; color:var(--text-muted);">— o ingresa con tarjeta / escáner —</div>

      <form id="login-card-form" style="display:flex; gap:8px;">
        <input type="text" id="login-card-code" class="form-control" placeholder="Escanea o escribe código de tarjeta (ej: VEND-001)">
        <button type="submit" class="btn btn-outline">Entrar</button>
      </form>

      <div class="login-demo-hint">
        <strong>Accesos iniciales (cámbialos en Tiendas y Vendedores):</strong><br>
        • Dueño/Admin: <code>admin</code> / <code>admin123</code><br>
        • Vendedor Tienda 1: <code>vendedor1</code> / <code>1234</code> (o tarjeta <code>VEND-001</code>)
      </div>
    </div>
  </div>

  <!-- ==================== ADMIN / DUEÑO SHELL ==================== -->
  <div id="admin-shell" class="app-shell" style="display:none;">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-icon">GI</div>
        <div>
          <div class="brand-title" id="sidebar-business-name">Mi Negocio Pyme</div>
          <div class="brand-sub">Panel Dueño / Administrador</div>
        </div>
      </div>

      <div class="store-switcher-box">
        <label class="store-switcher-label" for="admin-store-select">Tienda Activa</label>
        <select id="admin-store-select" class="store-select">
          <option value="all">🌐 Vista Global (Todas)</option>
        </select>
      </div>

      <nav class="nav-list">
        <button class="nav-btn active" data-view="dashboard">
          <span>📊</span> Resumen General
        </button>
        <button class="nav-btn" data-view="products">
          <span>📦</span> Productos y Stock
          <span class="nav-badge" id="nav-low-stock-badge" style="display:none;">0</span>
        </button>
        <button class="nav-btn" data-view="sales">
          <span>🛒</span> Registrar Venta
        </button>
        <button class="nav-btn" data-view="purchases">
          <span>🚚</span> Compras / Entradas
        </button>
        <button class="nav-btn" data-view="movements">
          <span>📋</span> Kardex / Movimientos
        </button>
        <button class="nav-btn" data-view="stores-users">
          <span>🏪</span> Tiendas y Vendedores
        </button>
        <button class="nav-btn" data-view="settings">
          <span>⚙️</span> Configuración
        </button>
      </nav>

      <div class="sidebar-footer">
        <div><span class="badge badge-green">Verde</span> = Ganancias netas</div>
        <div style="margin-top:6px;"><span class="badge badge-orange">Naranja</span> = Movimiento de dinero</div>
        <div class="user-footer-box">
          <div>
            <div style="color:#fff; font-weight:600;" id="admin-user-name">Admin</div>
            <div style="font-size:11px;">Administrador</div>
          </div>
          <button class="btn btn-sm btn-outline" id="btn-admin-logout" style="padding:5px 9px;">Salir</button>
        </div>
      </div>
    </aside>

    <main class="main-content">
      <!-- ==================== DASHBOARD ==================== -->
      <section id="view-dashboard" class="view-section active">
        <div class="topbar">
          <div>
            <h1 class="page-title">Resumen General <span id="dash-store-label" class="badge badge-blue" style="vertical-align:middle;">Vista Global</span></h1>
            <p class="page-subtitle">Control en tiempo real de stock, movimiento de dinero y ganancias netas</p>
          </div>
          <div class="topbar-actions">
            <button class="btn btn-outline" id="btn-load-demo" style="display:none;">✨ Cargar ejemplo (Lápiz Mina)</button>
            <button class="btn btn-orange" onclick="switchView('purchases')">+ Registrar Compra</button>
            <button class="btn btn-green" onclick="switchView('sales')">+ Registrar Venta</button>
          </div>
        </div>

        <div class="kpi-grid">
          <div class="kpi-card kpi-green">
            <div class="kpi-label">Ganancias Netas Totales</div>
            <div class="kpi-value num" id="kpi-total-profit">$0</div>
            <div class="kpi-sub">Hoy: <strong class="num" id="kpi-today-profit">$0</strong> (descontando costo, IVA e impuestos)</div>
          </div>

          <div class="kpi-card kpi-orange">
            <div class="kpi-label">Movimiento Total de Dinero</div>
            <div class="kpi-value num" id="kpi-total-money">$0</div>
            <div class="kpi-sub">Ventas: <strong class="num" id="kpi-sales-gross">$0</strong> · Compras: <strong class="num" id="kpi-purchases-total">$0</strong></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-label">Capital en Stock (Costo Compra)</div>
            <div class="kpi-value num" id="kpi-inv-cost">$0</div>
            <div class="kpi-sub">Venta potencial: <strong class="num" id="kpi-inv-sale">$0</strong> · Ganancia est.: <strong class="text-green num" id="kpi-inv-profit">$0</strong></div>
          </div>

          <div class="kpi-card">
            <div class="kpi-label">Impuestos &amp; Estado de Stock</div>
            <div class="kpi-value num" id="kpi-total-taxes">$0</div>
            <div class="kpi-sub"><span id="kpi-total-products">0</span> productos · <span id="kpi-low-stock-text">0 con stock bajo</span></div>
          </div>
        </div>

        <div class="card" id="dash-stores-card">
          <div class="card-header">
            <h2 class="card-title">🏪 Comparativa por Tienda (Global)</h2>
            <button class="btn btn-sm btn-outline" onclick="switchView('stores-users')">Gestionar tiendas</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Tienda</th>
                  <th class="text-right">Capital en Stock (Costo)</th>
                  <th class="text-right">Compras</th>
                  <th class="text-right">Ventas (Naranja)</th>
                  <th class="text-right">Ganancias (Verde)</th>
                  <th class="text-right">Ver</th>
                </tr>
              </thead>
              <tbody id="dash-stores-body"></tbody>
            </table>
          </div>
        </div>

        <div class="grid-2">
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">⚠️ Alertas de Stock Bajo</h2>
              <button class="btn btn-sm btn-outline" onclick="switchView('products')">Ver catálogo</button>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-right">Stock Actual</th>
                    <th class="text-right">Mínimo</th>
                    <th class="text-right">Acción</th>
                  </tr>
                </thead>
                <tbody id="dash-low-stock-body"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h2 class="card-title">🏆 Productos Más Rentables</h2>
              <span class="badge badge-green">Por ganancia acumulada</span>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-right">Vendido</th>
                    <th class="text-right">Total Venta</th>
                    <th class="text-right">Ganancia</th>
                  </tr>
                </thead>
                <tbody id="dash-top-products-body"></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">🔄 Últimos Movimientos (Entradas y Salidas)</h2>
            <button class="btn btn-sm btn-outline" onclick="switchView('movements')">Ver Kardex completo</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tienda</th>
                  <th>Tipo</th>
                  <th>Producto</th>
                  <th class="text-right">Cantidad</th>
                  <th class="text-right">Stock</th>
                  <th class="text-right">Mov. Dinero</th>
                  <th class="text-right">Ganancia</th>
                </tr>
              </thead>
              <tbody id="dash-recent-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ==================== PRODUCTS ==================== -->
      <section id="view-products" class="view-section">
        <div class="topbar">
          <div>
            <h1 class="page-title">Productos e Inventario</h1>
            <p class="page-subtitle">Control por cantidad, peso, volumen o paquetes, con costo, IVA, precio final y ganancia por unidad</p>
          </div>
          <div class="topbar-actions">
            <button class="btn btn-primary" id="btn-new-product">+ Nuevo Producto</button>
          </div>
        </div>

        <div class="card">
          <div class="filters-bar">
            <input type="text" id="prod-search" class="form-control" style="max-width:300px;" placeholder="Buscar por nombre o SKU...">
            <select id="prod-cat-filter" class="form-control" style="max-width:220px;">
              <option value="">Todas las categorías</option>
            </select>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Producto / SKU</th>
                  <th>Categoría</th>
                  <th class="text-right">Stock Actual</th>
                  <th class="text-right">Valor Stock (Costo)</th>
                  <th class="text-right">Costo Unit.</th>
                  <th class="text-right">Precio Final (c/Imp.)</th>
                  <th class="text-right">Ganancia / Unit.</th>
                  <th class="text-right">Acciones</th>
                </tr>
              </thead>
              <tbody id="products-table-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ==================== SALES (ADMIN) ==================== -->
      <section id="view-sales" class="view-section">
        <div class="topbar">
          <div>
            <h1 class="page-title">Registrar Venta (Salida)</h1>
            <p class="page-subtitle">Cálculo automático: Total precio menos IVA y otros impuestos, menos costo = Ganancias</p>
          </div>
        </div>

        <div class="grid-pos">
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Agregar Productos a la Venta</h2>
            </div>

            <div class="form-grid" style="margin-bottom:16px;">
              <div class="form-group" style="grid-column: span 2;">
                <label for="sale-product-select">Producto</label>
                <select id="sale-product-select" class="form-control"></select>
              </div>
              <div class="form-group">
                <label for="sale-qty-input">Cantidad / Peso / Vol.</label>
                <input type="number" id="sale-qty-input" class="form-control" step="any" min="0.001" value="1">
              </div>
              <div class="form-group" style="justify-content:flex-end;">
                <button type="button" class="btn btn-primary" id="btn-add-to-cart" style="margin-top:auto;">+ Agregar</button>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-right">Cantidad</th>
                    <th class="text-right">Precio Final Unit.</th>
                    <th class="text-right">Subtotal (Naranja)</th>
                    <th class="text-right">Ganancia (Verde)</th>
                    <th class="text-right"></th>
                  </tr>
                </thead>
                <tbody id="sale-cart-body"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Desglose Automático de Venta</h2>
            </div>

            <div class="calc-box" style="margin-bottom:16px;">
              <div class="calc-row highlight-orange">
                <span>Total Precio (Movimiento de dinero)</span>
                <span class="num" id="sale-sum-gross">$0</span>
              </div>
              <div class="calc-row">
                <span>(-) IVA Total</span>
                <span class="num text-muted" id="sale-sum-iva">$0</span>
              </div>
              <div class="calc-row">
                <span>(-) Otros Impuestos</span>
                <span class="num text-muted" id="sale-sum-other-tax">$0</span>
              </div>
              <div class="calc-row">
                <span><strong>(=) Venta Neta (sin impuestos)</strong></span>
                <strong class="num" id="sale-sum-net">$0</strong>
              </div>
              <div class="calc-row">
                <span>(-) Costo de Mercadería Vendida</span>
                <span class="num text-muted" id="sale-sum-cost">$0</span>
              </div>
              <div class="calc-row highlight-green">
                <span>(=) Ganancia Neta</span>
                <span class="num" id="sale-sum-profit">$0</span>
              </div>
            </div>

            <div class="form-grid" style="margin-bottom:12px;">
              <div class="form-group">
                <label for="sale-store-select">Tienda</label>
                <select id="sale-store-select" class="form-control"></select>
              </div>
              <div class="form-group">
                <label for="sale-payment-method">Medio de Pago</label>
                <select id="sale-payment-method" class="form-control">
                  <option value="cash">Efectivo</option>
                  <option value="card">Tarjeta (Transbank / MP)</option>
                  <option value="transfer">Transferencia</option>
                </select>
              </div>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
              <label for="sale-customer">Cliente (opcional)</label>
              <input type="text" id="sale-customer" class="form-control" placeholder="Ej: Cliente mostrador / Empresa X">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
              <label for="sale-notes">Nota / Comprobante (opcional)</label>
              <input type="text" id="sale-notes" class="form-control" placeholder="Ej: Boleta #104, pago efectivo">
            </div>

            <button type="button" class="btn btn-green" id="btn-confirm-sale" style="width:100%; padding:12px; font-size:15px;">
              ✅ Confirmar Venta y Descontar Stock
            </button>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">Historial Reciente de Ventas</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Fecha</th>
                  <th>Tienda / Vendedor</th>
                  <th>Cliente / Detalle</th>
                  <th class="text-right">Total Venta (Dinero)</th>
                  <th class="text-right">IVA + Imp.</th>
                  <th class="text-right">Costo</th>
                  <th class="text-right">Ganancia Neta</th>
                </tr>
              </thead>
              <tbody id="sales-history-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ==================== PURCHASES ==================== -->
      <section id="view-purchases" class="view-section">
        <div class="topbar">
          <div>
            <h1 class="page-title">Compras y Entradas de Mercadería</h1>
            <p class="page-subtitle">Registra lo que llega a cada tienda, actualiza el stock y el valor de inversión en inventario</p>
          </div>
        </div>

        <div class="grid-pos">
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Nueva Compra / Entrada</h2>
            </div>

            <form id="purchase-form">
              <div class="form-grid">
                <div class="form-group">
                  <label for="pur-store">Tienda Destino</label>
                  <select id="pur-store" class="form-control" required></select>
                </div>
                <div class="form-group">
                  <label for="pur-product">Producto</label>
                  <select id="pur-product" class="form-control" required></select>
                </div>
                <div class="form-group">
                  <label for="pur-qty">Cantidad Comprada (<span id="pur-unit-label">unid.</span>)</label>
                  <input type="number" id="pur-qty" class="form-control" step="any" min="0.001" value="1" required>
                </div>
                <div class="form-group">
                  <label for="pur-cost">Costo Unitario de Compra</label>
                  <input type="number" id="pur-cost" class="form-control" step="0.01" min="0" value="0" required>
                </div>
                <div class="form-group">
                  <label for="pur-supplier">Proveedor (opcional)</label>
                  <input type="text" id="pur-supplier" class="form-control" placeholder="Ej: Distribuidora Central">
                </div>
                <div class="form-group">
                  <label for="pur-notes">Factura / Nota (opcional)</label>
                  <input type="text" id="pur-notes" class="form-control" placeholder="Ej: Factura #5521">
                </div>
              </div>

              <div style="margin-top:18px;">
                <button type="submit" class="btn btn-orange" style="padding:11px 20px;">
                  🚚 Registrar Compra y Sumar Stock
                </button>
              </div>
            </form>
          </div>

          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Impacto en Stock e Inversión</h2>
            </div>
            <div class="calc-box">
              <div class="calc-row highlight-orange">
                <span>Total Inversión Compra (Dinero)</span>
                <span class="num" id="pur-calc-total">$0</span>
              </div>
              <div class="calc-row">
                <span>Stock Actual en Tienda</span>
                <span class="num" id="pur-calc-stock-before">0</span>
              </div>
              <div class="calc-row">
                <span>Nuevo Stock Resultante</span>
                <strong class="num" id="pur-calc-stock-after">0</strong>
              </div>
              <div class="calc-row">
                <span>Nuevo Valor Total en Stock (Costo)</span>
                <strong class="num" id="pur-calc-stock-value">$0</strong>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">Historial Reciente de Compras</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tienda</th>
                  <th>Producto</th>
                  <th class="text-right">Cantidad</th>
                  <th class="text-right">Costo Unit.</th>
                  <th class="text-right">Total Compra (Dinero)</th>
                  <th class="text-right">Stock Nuevo</th>
                  <th>Proveedor / Nota</th>
                </tr>
              </thead>
              <tbody id="purchases-history-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ==================== MOVEMENTS / KARDEX ==================== -->
      <section id="view-movements" class="view-section">
        <div class="topbar">
          <div>
            <h1 class="page-title">Kardex Minucioso de Movimientos</h1>
            <p class="page-subtitle">Trazabilidad completa de cada entrada, salida, ajuste o merma</p>
          </div>
          <div class="topbar-actions">
            <button class="btn btn-outline" id="btn-export-csv">⬇️ Exportar CSV</button>
            <button class="btn btn-primary" id="btn-open-adjustment">± Registrar Ajuste / Merma</button>
          </div>
        </div>

        <div class="card">
          <div class="filters-bar">
            <select id="mov-filter-product" class="form-control" style="max-width:240px;">
              <option value="">Todos los productos</option>
            </select>
            <select id="mov-filter-type" class="form-control" style="max-width:200px;">
              <option value="">Todos los tipos</option>
              <option value="purchase">Compra (Entrada)</option>
              <option value="sale">Venta (Salida)</option>
              <option value="adjustment_in">Ajuste Entrada</option>
              <option value="adjustment_out">Ajuste Salida / Merma</option>
            </select>
            <input type="date" id="mov-filter-from" class="form-control" style="max-width:160px;">
            <input type="date" id="mov-filter-to" class="form-control" style="max-width:160px;">
            <button class="btn btn-outline" id="btn-filter-movements">Filtrar</button>
          </div>

          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tienda</th>
                  <th>Tipo</th>
                  <th>Producto</th>
                  <th class="text-right">Cantidad</th>
                  <th class="text-right">Stock Ant. → Nuevo</th>
                  <th class="text-right">Mov. Dinero</th>
                  <th class="text-right">IVA + Imp.</th>
                  <th class="text-right">Ganancia</th>
                  <th>Contraparte / Nota</th>
                </tr>
              </thead>
              <tbody id="movements-table-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ==================== STORES & EMPLOYEES ==================== -->
      <section id="view-stores-users" class="view-section">
        <div class="topbar">
          <div>
            <h1 class="page-title">Tiendas y Empleados de Venta</h1>
            <p class="page-subtitle">Organiza tus sucursales y controla las ventas diarias de cada vendedor</p>
          </div>
          <div class="topbar-actions">
            <button class="btn btn-outline" id="btn-new-store">+ Nueva Tienda</button>
            <button class="btn btn-primary" id="btn-new-user">+ Nuevo Vendedor / Usuario</button>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">🏪 Sucursales / Tiendas</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Tienda</th>
                  <th>Dirección / Teléfono</th>
                  <th class="text-right">Vendedores</th>
                  <th class="text-right">Stock en Costo</th>
                  <th class="text-right">Ventas Totales</th>
                  <th class="text-right">Ganancias</th>
                </tr>
              </thead>
              <tbody id="stores-table-body"></tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <h2 class="card-title">👥 Vendedores y Ventas Diarias</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Nombre / Usuario</th>
                  <th>Rol</th>
                  <th>Tienda Asignada</th>
                  <th>Tarjeta / Código</th>
                  <th class="text-right">Ventas Hoy</th>
                  <th class="text-right">Vendido Hoy (Naranja)</th>
                  <th class="text-right">Ganancia Hoy (Verde)</th>
                  <th class="text-right">Acciones</th>
                </tr>
              </thead>
              <tbody id="users-table-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ==================== SETTINGS ==================== -->
      <section id="view-settings" class="view-section">
        <div class="topbar">
          <div>
            <h1 class="page-title">Configuración del Negocio</h1>
            <p class="page-subtitle">Personaliza moneda, impuestos por defecto y método de costeo</p>
          </div>
        </div>

        <div class="grid-2">
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Parámetros Generales</h2>
            </div>
            <form id="settings-form">
              <div class="form-grid">
                <div class="form-group">
                  <label for="set-business-name">Nombre del Negocio</label>
                  <input type="text" id="set-business-name" class="form-control" required>
                </div>
                <div class="form-group">
                  <label for="set-currency">Símbolo de Moneda</label>
                  <input type="text" id="set-currency" class="form-control" maxlength="8" required>
                </div>
                <div class="form-group">
                  <label for="set-iva">IVA por defecto (%)</label>
                  <input type="number" id="set-iva" class="form-control" step="0.01" min="0" max="100" required>
                </div>
                <div class="form-group">
                  <label for="set-other-tax">Otro Impuesto por defecto (%)</label>
                  <input type="number" id="set-other-tax" class="form-control" step="0.01" min="0" max="100" required>
                </div>
                <div class="form-group">
                  <label for="set-decimals">Decimales en precios</label>
                  <select id="set-decimals" class="form-control">
                    <option value="0">0 decimales (ej: $1.500)</option>
                    <option value="2">2 decimales (ej: $15.50)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="set-cost-method">Método de Costeo en Compras</label>
                  <select id="set-cost-method" class="form-control">
                    <option value="weighted_avg">Costo Promedio Ponderado</option>
                    <option value="last_cost">Último Costo de Compra</option>
                  </select>
                </div>
              </div>
              <div style="margin-top:18px;">
                <button type="submit" class="btn btn-primary">Guardar Configuración</button>
              </div>
            </form>
          </div>

          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Categorías de Productos</h2>
            </div>
            <form id="category-form" style="display:flex; gap:10px; margin-bottom:16px;">
              <input type="text" id="new-cat-name" class="form-control" placeholder="Nueva categoría..." required>
              <input type="color" id="new-cat-color" value="#3b82f6" style="width:46px; height:38px; border:1px solid var(--border); border-radius:8px; padding:2px;">
              <button type="submit" class="btn btn-outline">Agregar</button>
            </form>
            <div id="categories-list" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- ==================== SELLER / VENDEDOR POS SHELL ==================== -->
  <div id="seller-shell" class="seller-shell" style="display:none;">
    <header class="seller-topbar">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="brand-icon" style="width:34px; height:34px; font-size:14px;">POS</div>
        <div>
          <div style="font-weight:700; font-size:15px;" id="seller-business-name">Mi Negocio Pyme</div>
          <div style="font-size:12px; color:#94a3b8;">
            <span id="seller-store-name">Tienda 1</span> · Vendedor: <strong style="color:#fff;" id="seller-user-name">Vendedor</strong>
          </div>
        </div>
      </div>

      <div class="seller-nav">
        <button class="seller-nav-btn active" data-seller-view="pos">🛒 Punto de Venta</button>
        <button class="seller-nav-btn" data-seller-view="stock">📦 Stock Disponible</button>
        <button class="seller-nav-btn" data-seller-view="mysales">🧾 Mis Ventas</button>
        <button class="btn btn-sm btn-outline" id="btn-seller-logout" style="color:#fff; border-color:rgba(255,255,255,0.25); background:transparent;">Salir</button>
      </div>
    </header>

    <main class="seller-main">
      <!-- Seller POS -->
      <section id="seller-view-pos" class="seller-view">
        <div class="grid-pos">
          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Escanear o Buscar Producto</h2>
            </div>

            <div class="form-grid" style="margin-bottom:14px;">
              <div class="form-group" style="grid-column: span 2;">
                <label for="seller-scan-input">Código SKU / Escáner o Nombre</label>
                <input type="text" id="seller-scan-input" class="form-control" placeholder="Escanea código de barras o escribe nombre y presiona Enter...">
              </div>
              <div class="form-group">
                <label for="seller-qty-input">Cantidad</label>
                <input type="number" id="seller-qty-input" class="form-control" step="any" min="0.001" value="1">
              </div>
            </div>

            <div class="form-grid" style="margin-bottom:16px;">
              <div class="form-group" style="grid-column: span 2;">
                <label for="seller-product-select">O selecciona de la lista</label>
                <select id="seller-product-select" class="form-control"></select>
              </div>
              <div class="form-group" style="justify-content:flex-end;">
                <button type="button" class="btn btn-primary" id="btn-seller-add" style="margin-top:auto;">+ Agregar</button>
              </div>
            </div>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="text-right">Cantidad</th>
                    <th class="text-right">Precio Unit.</th>
                    <th class="text-right">Subtotal</th>
                    <th class="text-right"></th>
                  </tr>
                </thead>
                <tbody id="seller-cart-body"></tbody>
              </table>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h2 class="card-title">Cobro y Vuelto Automático</h2>
            </div>

            <div class="calc-box" style="margin-bottom:14px;">
              <div class="calc-row highlight-orange" style="font-size:20px;">
                <span>TOTAL A PAGAR</span>
                <span class="num" id="seller-total-gross">$0</span>
              </div>
            </div>

            <div class="form-group" style="margin-bottom:12px;">
              <label for="seller-payment-method">Medio de Pago</label>
              <select id="seller-payment-method" class="form-control">
                <option value="cash">💵 Efectivo</option>
                <option value="card">💳 Tarjeta (Transbank / Mercado Pago)</option>
                <option value="transfer">🏦 Transferencia</option>
              </select>
            </div>

            <div id="seller-cash-box" style="margin-bottom:14px;">
              <div class="form-group">
                <label for="seller-cash-received">Cliente paga con ($)</label>
                <input type="number" id="seller-cash-received" class="form-control num" step="1" min="0" placeholder="Ej: 10000">
              </div>
              <div class="change-box">
                <span style="font-weight:700; color:var(--profit-green);">VUELTO A ENTREGAR</span>
                <span class="num" id="seller-cash-change" style="font-size:20px; font-weight:800; color:var(--profit-green);">$0</span>
              </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
              <label for="seller-notes">N° Voucher / Nota (opcional)</label>
              <input type="text" id="seller-notes" class="form-control" placeholder="Ej: Operación Transbank #1234">
            </div>

            <button type="button" class="btn btn-green" id="btn-seller-confirm" style="width:100%; padding:13px; font-size:15.5px;">
              ✅ Cobrar y Registrar Venta
            </button>
          </div>
        </div>
      </section>

      <!-- Seller Stock -->
      <section id="seller-view-stock" class="seller-view" style="display:none;">
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">Stock Disponible en Tienda</h2>
            <input type="text" id="seller-stock-search" class="form-control" style="max-width:280px;" placeholder="Buscar producto o SKU...">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Producto / SKU</th>
                  <th>Categoría</th>
                  <th class="text-right">Stock Disponible</th>
                  <th class="text-right">Precio Final</th>
                </tr>
              </thead>
              <tbody id="seller-stock-body"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- Seller My Sales -->
      <section id="seller-view-mysales" class="seller-view" style="display:none;">
        <div class="card">
          <div class="card-header">
            <h2 class="card-title">Mis Ventas Registradas</h2>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Fecha</th>
                  <th>Detalle</th>
                  <th>Pago</th>
                  <th class="text-right">Total</th>
                  <th class="text-right">Vuelto</th>
                </tr>
              </thead>
              <tbody id="seller-sales-body"></tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <!-- ==================== MODAL: PRODUCTO ==================== -->
  <div class="modal-backdrop" id="modal-product">
    <div class="modal">
      <div class="modal-header">
        <h2 class="card-title" id="modal-product-title">Nuevo Producto</h2>
        <button class="modal-close" onclick="closeModal('modal-product')">&times;</button>
      </div>

      <form id="product-form">
        <input type="hidden" id="p-id">
        <div class="form-grid" style="margin-bottom:14px;">
          <div class="form-group" style="grid-column: span 2;">
            <label for="p-name">Nombre del Producto *</label>
            <input type="text" id="p-name" class="form-control" placeholder="Ej: Lápiz Mina HB, Arroz Grado 1, Aceite Vegetal" required>
          </div>
          <div class="form-group">
            <label for="p-sku">SKU / Código de Barras</label>
            <input type="text" id="p-sku" class="form-control" placeholder="Escanea o escribe SKU">
          </div>
          <div class="form-group">
            <label for="p-category">Categoría</label>
            <select id="p-category" class="form-control"></select>
          </div>
          <div class="form-group">
            <label for="p-measure-type">Tipo de Medida</label>
            <select id="p-measure-type" class="form-control">
              <option value="unit">Cantidad (unidades)</option>
              <option value="weight">Peso (kg, g, lb)</option>
              <option value="volume">Volumen (L, ml)</option>
              <option value="length">Longitud (m, cm)</option>
              <option value="pack">Paquete / Caja</option>
            </select>
          </div>
          <div class="form-group">
            <label for="p-unit-label">Etiqueta de Unidad *</label>
            <input type="text" id="p-unit-label" class="form-control" value="unid." placeholder="unid., kg, L, m, caja" required>
          </div>
          <div class="form-group" id="p-stock-group">
            <label for="p-stock">Stock Inicial</label>
            <input type="number" id="p-stock" class="form-control" step="any" min="0" value="0">
          </div>
          <div class="form-group">
            <label for="p-min-stock">Stock Mínimo (alerta)</label>
            <input type="number" id="p-min-stock" class="form-control" step="any" min="0" value="5">
          </div>
        </div>

        <hr style="border:none; border-top:1px solid var(--border); margin:16px 0;">
        <h3 style="font-size:14px; font-weight:700; margin-bottom:12px;">Calculadora de Costo, Impuestos, Precio Final y Ganancia</h3>

        <div class="form-grid" style="margin-bottom:14px;">
          <div class="form-group">
            <label for="p-cost">Costo de Compra (por unidad)</label>
            <input type="number" id="p-cost" class="form-control" step="0.01" min="0" value="0" required>
          </div>
          <div class="form-group">
            <label for="p-margin-pct">Ganancia Deseada (%)</label>
            <input type="number" id="p-margin-pct" class="form-control" step="0.01" min="0" value="0" placeholder="Ej: 2, 20, 35">
          </div>
          <div class="form-group">
            <label for="p-iva">IVA (%)</label>
            <input type="number" id="p-iva" class="form-control" step="0.01" min="0" max="100" value="19" required>
          </div>
          <div class="form-group">
            <label for="p-other-tax">Otro Impuesto (%)</label>
            <input type="number" id="p-other-tax" class="form-control" step="0.01" min="0" max="100" value="0" required>
          </div>
          <div class="form-group">
            <label for="p-sale-net">Precio Venta Neto (sin imp.)</label>
            <input type="number" id="p-sale-net" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="form-group">
            <label for="p-sale-final">Precio Final al Cliente (c/imp.)</label>
            <input type="number" id="p-sale-final" class="form-control" step="0.01" min="0" value="0">
          </div>
        </div>

        <div class="calc-box" style="margin-bottom:18px;">
          <div class="calc-row">
            <span>Precio Venta Neto (Costo + Ganancia)</span>
            <strong class="num" id="p-calc-net">$0</strong>
          </div>
          <div class="calc-row">
            <span>(+) Impuestos por unidad (IVA + Otro)</span>
            <span class="num" id="p-calc-taxes">$0</span>
          </div>
          <div class="calc-row highlight-orange">
            <span>(=) Precio Final al Cliente</span>
            <span class="num" id="p-calc-final">$0</span>
          </div>
          <div class="calc-row highlight-green">
            <span>Ganancia Neta por Unidad (<span id="p-calc-margin">0%</span>)</span>
            <span class="num" id="p-calc-profit">$0</span>
          </div>
          <div class="calc-row" id="p-calc-stock-val-row">
            <span>Valor del Stock Inicial en Costo</span>
            <strong class="num" id="p-calc-stock-val">$0</strong>
          </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-outline" onclick="closeModal('modal-product')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Producto</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================== MODAL: AJUSTE / MERMA ==================== -->
  <div class="modal-backdrop" id="modal-adjustment">
    <div class="modal" style="max-width:480px;">
      <div class="modal-header">
        <h2 class="card-title">Registrar Ajuste de Inventario</h2>
        <button class="modal-close" onclick="closeModal('modal-adjustment')">&times;</button>
      </div>
      <form id="adjustment-form">
        <div class="form-group" style="margin-bottom:12px;">
          <label for="adj-store">Tienda</label>
          <select id="adj-store" class="form-control" required></select>
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="adj-product">Producto</label>
          <select id="adj-product" class="form-control" required></select>
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="adj-type">Tipo de Ajuste</label>
          <select id="adj-type" class="form-control" required>
            <option value="adjustment_out">Salida / Merma / Daño (-)</option>
            <option value="adjustment_in">Entrada / Devolución / Sobrante (+)</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="adj-qty">Cantidad</label>
          <input type="number" id="adj-qty" class="form-control" step="any" min="0.001" value="1" required>
        </div>
        <div class="form-group" style="margin-bottom:18px;">
          <label for="adj-notes">Motivo</label>
          <input type="text" id="adj-notes" class="form-control" placeholder="Ej: Producto dañado, conteo físico" required>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-outline" onclick="closeModal('modal-adjustment')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Aplicar Ajuste</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================== MODAL: NUEVA TIENDA ==================== -->
  <div class="modal-backdrop" id="modal-store">
    <div class="modal" style="max-width:460px;">
      <div class="modal-header">
        <h2 class="card-title">Nueva Tienda / Sucursal</h2>
        <button class="modal-close" onclick="closeModal('modal-store')">&times;</button>
      </div>
      <form id="store-form">
        <div class="form-group" style="margin-bottom:12px;">
          <label for="st-name">Nombre de la Tienda *</label>
          <input type="text" id="st-name" class="form-control" placeholder="Ej: Tienda 2 - Sucursal Centro" required>
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="st-address">Dirección (opcional)</label>
          <input type="text" id="st-address" class="form-control" placeholder="Ej: Av. Principal 450">
        </div>
        <div class="form-group" style="margin-bottom:18px;">
          <label for="st-phone">Teléfono (opcional)</label>
          <input type="text" id="st-phone" class="form-control" placeholder="Ej: +56 9 1234 5678">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-outline" onclick="closeModal('modal-store')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Crear Tienda</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================== MODAL: NUEVO / EDITAR USUARIO ==================== -->
  <div class="modal-backdrop" id="modal-user">
    <div class="modal" style="max-width:520px;">
      <div class="modal-header">
        <h2 class="card-title" id="modal-user-title">Nuevo Vendedor / Usuario</h2>
        <button class="modal-close" onclick="closeModal('modal-user')">&times;</button>
      </div>
      <form id="user-form">
        <input type="hidden" id="u-id">
        <div class="form-grid" style="margin-bottom:14px;">
          <div class="form-group">
            <label for="u-full-name">Nombre Completo *</label>
            <input type="text" id="u-full-name" class="form-control" placeholder="Ej: Juan Pérez" required>
          </div>
          <div class="form-group">
            <label for="u-username">Usuario *</label>
            <input type="text" id="u-username" class="form-control" placeholder="Ej: jperez" required>
          </div>
          <div class="form-group">
            <label for="u-role">Rol</label>
            <select id="u-role" class="form-control">
              <option value="seller">Vendedor (solo POS y Stock)</option>
              <option value="admin">Dueño / Administrador</option>
            </select>
          </div>
          <div class="form-group">
            <label for="u-store">Tienda Asignada</label>
            <select id="u-store" class="form-control"></select>
          </div>
          <div class="form-group">
            <label for="u-password">Contraseña / PIN <span id="u-pass-hint" class="text-muted">(obligatoria)</span></label>
            <input type="password" id="u-password" class="form-control" placeholder="••••••••">
          </div>
          <div class="form-group">
            <label for="u-card-code">Código de Tarjeta (Escáner)</label>
            <input type="text" id="u-card-code" class="form-control" placeholder="Ej: VEND-002">
          </div>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px;">
          <button type="button" class="btn btn-outline" onclick="closeModal('modal-user')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Usuario</button>
        </div>
      </form>
    </div>
  </div>

  <div class="toast-container" id="toast-container"></div>

  <script src="assets/js/app.js"></script>
</body>
</html>
