export default {
  name: 'LoginPage',

  template: `
    <section id="login">
      <div class="login-card">
        <div class="login-head">
          <h2>Welcome Back</h2>
          <p>Sign in to your account</p>
        </div>

        <form @submit.prevent="handleSubmit" class="login-form">
          <div class="login-field">
            <label for="email">Email Address</label>
            <input
              id="email"
              type="email"
              v-model="form.email"
              placeholder="you@example.com"
              autocomplete="email"
              required
            />
          </div>

          <div class="login-field">
            <label for="password">Password</label>

            <div class="login-password">
              <input
                id="password"
                :type="showPassword ? 'text' : 'password'"
                v-model="form.password"
                placeholder="••••••••"
                autocomplete="current-password"
                required
              />
              <button type="button" class="btn-icon" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'">
                <i :class="showPassword ? 'bx bx-hide' : 'bx bx-show'"></i>
              </button>
            </div>
          </div>

          <p v-if="error" class="login-error">
            <i class='bx bxs-error-circle'></i>
            {{ error }}
          </p>

          <button type="submit" class="btn-primary" :disabled="loading">
            <span v-if="loading">Signing in...</span>
            <span v-else>Sign In</span>
          </button>

          <div class="login-foot">
            <a href="#" class="link-muted">Forgot password?</a>
            <span class="dot"></span>
            <a href="#" class="link-muted">Create account</a>
          </div>
        </form>
      </div>
    </section>
  `,

  data() {
    return {
      form: { email: '', password: '' },
      loading: false,
      error: null,
      showPassword: false,
    };
  },

  methods: {
    async handleSubmit() {
      this.loading = true;
      this.error = null;

      try {
        const response = await fetch('/api/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(this.form),
        });

        const data = await response.json().catch(() => ({}));

        if (response.ok) {
          window.location.href = '/';
        } else {
          this.error = data.message || 'Login failed';
        }
      } catch (e) {
        this.error = 'An error occurred. Please try again.';
        console.error(e);
      } finally {
        this.loading = false;
      }
    },
  },
};
