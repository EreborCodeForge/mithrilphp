export default {
  name: 'SalesReport',

  data() {
    return { menuOpen: false, chart: null };
  },

  mounted() {
    // ApexCharts via CDN
    if (!window.ApexCharts) return;

    const el = this.$refs.chart;
    const options = {
      chart: { type: 'area', height: 280, toolbar: { show: false } },
      series: [{ name: 'Sales', data: [10, 22, 18, 35, 28, 46, 40, 62, 55, 78, 70, 92] }],
      xaxis: { categories: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] },
      dataLabels: { enabled: false },
      stroke: { curve: 'smooth' },
    };

    this.chart = new window.ApexCharts(el, options);
    this.chart.render();

    document.addEventListener('click', this.onDocClick);
  },

  beforeUnmount() {
    document.removeEventListener('click', this.onDocClick);
    try { this.chart?.destroy(); } catch {}
  },

  methods: {
    onDocClick(e) {
      const menu = this.$refs.menu;
      if (!menu) return;
      if (!menu.contains(e.target)) this.menuOpen = false;
    },
  },

  template: `
    <div class="content-data">
      <div class="head">
        <h3>Sales Report</h3>

        <div class="menu" ref="menu" @click.stop="menuOpen = !menuOpen">
          <i class='bx bx-dots-horizontal-rounded icon'></i>
          <ul class="menu-link" v-show="menuOpen">
            <li><a href="#">Edit</a></li>
            <li><a href="#">Save</a></li>
            <li><a href="#">Remove</a></li>
          </ul>
        </div>
      </div>

      <div class="chart">
        <div ref="chart"></div>
      </div>
    </div>
  `,
};
