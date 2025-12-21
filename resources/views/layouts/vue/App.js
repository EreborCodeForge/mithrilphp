import Sidebar from './components/Sidebar.js';
import Navbar from './components/Navbar.js';
import DashboardMain from './components/DashboardMain.js';

export default {
  name: 'AdminApp',
  components: { Sidebar, Navbar, DashboardMain },

  data() {
    return {
      sidebarHidden: false,
      activePage: 'Dashboard',
      breadcrumbs: ['Home', 'Dashboard'],
      notifications: 5,
      messages: 8,
      user: {
        name: 'Alan',
        avatar:
          'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=500&q=60',
      },
    };
  },

  methods: {
    toggleSidebar() {
      this.sidebarHidden = !this.sidebarHidden;
    },
  },

  template: `
    <section id="sidebar" :class="{ hide: sidebarHidden }">
      <Sidebar :activePage="activePage" />
    </section>

    <section id="content">
      <Navbar
        :notifications="notifications"
        :messages="messages"
        :user="user"
        @toggle-sidebar="toggleSidebar"
      />

      <DashboardMain />
    </section>
  `,
};
