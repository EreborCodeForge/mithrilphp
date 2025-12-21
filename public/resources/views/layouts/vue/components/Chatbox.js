export default {
  name: 'Chatbox',

  data() {
    return {
      menuOpen: false,
      input: '',
      avatar:
        'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=500&q=60',
      messages: [
        { day: 'Today' },
        { from: 'Alan', time: '18:30', text: 'Hello', avatar: true },
        { me: true, time: '18:30', text: 'Lorem ipsum dolor sit amet consectetur adipisicing elit. Eaque voluptatum eos quam dolores eligendi exercitationem animi nobis reprehenderit laborum! Nulla.' },
        { me: true, time: '18:30', text: 'Lorem ipsum dolor sit amet consectetur adipisicing elit. Ipsam, architecto!' },
        { me: true, time: '18:30', text: 'Lorem ipsum, dolor sit amet.' },
      ],
    };
  },

  mounted() {
    document.addEventListener('click', this.onDocClick);
  },

  beforeUnmount() {
    document.removeEventListener('click', this.onDocClick);
  },

  methods: {
    onDocClick(e) {
      const menu = this.$refs.menu;
      if (!menu) return;
      if (!menu.contains(e.target)) this.menuOpen = false;
    },

    send() {
      const text = this.input.trim();
      if (!text) return;

      const now = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      this.messages.push({ me: true, time: now, text });

      this.input = '';

      this.$nextTick(() => {
        const box = this.$refs.box;
        if (box) box.scrollTop = box.scrollHeight;
      });
    },
  },

  template: `
    <div class="content-data">
      <div class="head">
        <h3>Chatbox</h3>

        <div class="menu" ref="menu" @click.stop="menuOpen = !menuOpen">
          <i class='bx bx-dots-horizontal-rounded icon'></i>
          <ul class="menu-link" v-show="menuOpen">
            <li><a href="#">Edit</a></li>
            <li><a href="#">Save</a></li>
            <li><a href="#">Remove</a></li>
          </ul>
        </div>
      </div>

      <div class="chat-box" ref="box">
        <template v-for="(m, i) in messages" :key="i">
          <p v-if="m.day" class="day"><span>{{ m.day }}</span></p>

          <div v-else-if="!m.me" class="msg">
            <img v-if="m.avatar" :src="avatar" alt="">
            <div class="chat">
              <div class="profile">
                <span class="username">{{ m.from }}</span>
                <span class="time">{{ m.time }}</span>
              </div>
              <p>{{ m.text }}</p>
            </div>
          </div>

          <div v-else class="msg me">
            <div class="chat">
              <div class="profile">
                <span class="time">{{ m.time }}</span>
              </div>
              <p>{{ m.text }}</p>
            </div>
          </div>
        </template>
      </div>

      <form action="#" @submit.prevent="send">
        <div class="form-group">
          <input v-model="input" type="text" placeholder="Type...">
          <button type="submit" class="btn-send">
            <i class='bx bxs-send'></i>
          </button>
        </div>
      </form>
    </div>
  `,
};
