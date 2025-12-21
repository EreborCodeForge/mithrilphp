export default {
  name: 'InfoCards',

  data() {
    return {
      cards: [
        { value: 1500, label: 'Traffic', icon: 'bx bx-trending-up icon', progress: 40 },
        { value: 234, label: 'Sales', icon: 'bx bx-trending-down icon down', progress: 60 },
        { value: 465, label: 'Pageviews', icon: 'bx bx-trending-up icon', progress: 30 },
        { value: 235, label: 'Visitors', icon: 'bx bx-trending-up icon', progress: 80 },
      ],
    };
  },

  template: `
    <div class="info-data">
      <div class="card" v-for="(c, i) in cards" :key="i">
        <div class="head">
          <div>
            <h2>{{ c.value }}</h2>
            <p>{{ c.label }}</p>
          </div>
          <i :class="c.icon"></i>
        </div>

        <span class="progress" :style="{ width: c.progress + '%' }"></span>
        <span class="label">{{ c.progress }}%</span>
      </div>
    </div>
  `,
};
