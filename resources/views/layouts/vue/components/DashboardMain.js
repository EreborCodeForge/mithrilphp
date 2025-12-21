import InfoCards from './InfoCards.js';
import SalesReport from './SalesReport.js';
import Chatbox from './Chatbox.js';

export default {
  name: 'DashboardMain',
  components: { InfoCards, SalesReport, Chatbox },

  template: `
    <main>
      <h1 class="title">Dashboard</h1>

      <ul class="breadcrumbs">
        <li><a href="#">Home</a></li>
        <li class="divider">/</li>
        <li><a href="#" class="active">Dashboard</a></li>
      </ul>

      <InfoCards />

      <div class="data">
        <SalesReport />
        <Chatbox />
      </div>
    </main>
  `,
};
