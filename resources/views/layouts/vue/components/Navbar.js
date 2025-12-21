export default {
  name: 'Navbar',
  emits: ['toggle-sidebar'],
  props: {
    notifications: { type: Number, default: 0 },
    messages: { type: Number, default: 0 },
    user: { type: Object, required: true },
  },

  data() {
    return {
      profileOpen: false,
      search: '',
    };
  },

  mounted() {
    // fecha dropdown clicando fora
    document.addEventListener('click', this.onDocClick);
  },

  beforeUnmount() {
    document.removeEventListener('click', this.onDocClick);
  },

  methods: {
    onDocClick(e) {
      const profile = this.$refs.profile;
      if (!profile) return;
      if (!profile.contains(e.target)) this.profileOpen = false;
    },
  },

  template: `
    <nav>
      <i class='bx bx-menu toggle-sidebar' @click="$emit('toggle-sidebar')"></i>

      <form action="#" @submit.prevent>
        <div class="form-group">
          <input v-model="search" type="text" placeholder="Search...">
          <i class='bx bx-search icon'></i>
        </div>
      </form>

      <a href="#" class="nav-link">
        <i class='bx bxs-bell icon'></i>
        <span class="badge">{{ notifications }}</span>
      </a>

      <a href="#" class="nav-link">
        <i class='bx bxs-message-square-dots icon'></i>
        <span class="badge">{{ messages }}</span>
      </a>

      <span class="divider"></span>

      <div class="profile" ref="profile" @click.stop="profileOpen = !profileOpen">
        <img :src="user.avatar" alt="">
        <ul class="profile-link" v-show="profileOpen">
          <li><a href="#"><i class='bx bxs-user-circle icon'></i> Profile</a></li>
          <li><a href="#"><i class='bx bxs-cog'></i> Settings</a></li>
          <li><a href="#"><i class='bx bxs-log-out-circle'></i> Logout</a></li>
        </ul>
      </div>
    </nav>
  `,
};
