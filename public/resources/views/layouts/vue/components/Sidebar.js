export default {
  name: 'Sidebar',
  props: {
    activePage: { type: String, default: 'Dashboard' },
  },

  data() {
    return {
      // dropdowns abertos
      open: {
        elements: false,
        forms: false,
      },
    };
  },

  methods: {
    toggle(key) {
      // fecha outros se quiser comportamento “only one open”
      Object.keys(this.open).forEach((k) => {
        if (k !== key) this.open[k] = false;
      });
      this.open[key] = !this.open[key];
    },
  },

  template: `
    <a href="#" class="brand"><i class='bx bxs-smile icon'></i> AdminSite</a>

    <ul class="side-menu">
      <li>
        <a href="#" :class="{ active: activePage === 'Dashboard' }">
          <i class='bx bxs-dashboard icon'></i> Dashboard
        </a>
      </li>

      <li class="divider" data-text="main">Main</li>

      <li :class="{ active: open.elements }">
        <a href="#" @click.prevent="toggle('elements')">
          <i class='bx bxs-inbox icon'></i> Elements
          <i class='bx bx-chevron-right icon-right'></i>
        </a>

        <ul class="side-dropdown" v-show="open.elements">
          <li><a href="#">Alert</a></li>
          <li><a href="#">Badges</a></li>
          <li><a href="#">Breadcrumbs</a></li>
          <li><a href="#">Button</a></li>
        </ul>
      </li>

      <li><a href="#"><i class='bx bxs-chart icon'></i> Charts</a></li>
      <li><a href="#"><i class='bx bxs-widget icon'></i> Widgets</a></li>

      <li class="divider" data-text="table and forms">Table and forms</li>

      <li><a href="#"><i class='bx bx-table icon'></i> Tables</a></li>

      <li :class="{ active: open.forms }">
        <a href="#" @click.prevent="toggle('forms')">
          <i class='bx bxs-notepad icon'></i> Forms
          <i class='bx bx-chevron-right icon-right'></i>
        </a>

        <ul class="side-dropdown" v-show="open.forms">
          <li><a href="#">Basic</a></li>
          <li><a href="#">Select</a></li>
          <li><a href="#">Checkbox</a></li>
          <li><a href="#">Radio</a></li>
        </ul>
      </li>
    </ul>

    <div class="ads">
      <div class="wrapper">
        <a href="#" class="btn-upgrade">Upgrade</a>
        <p>Become a <span>PRO</span> member and enjoy <span>All Features</span></p>
      </div>
    </div>
  `,
};
