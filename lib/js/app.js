var xhr = new XMLHttpRequest();
xhr.open('GET', './db/magento2events.db', true);
xhr.responseType = 'arraybuffer';
var db;
xhr.send();

new Vue({
  el: '#magento2events',
  data: {
    eventFilter: {version: '', module: '', searchText: ''},
    versions: [],
    modules: [],
    events: []
  },
  computed: {
    foundEvents: function() {
      var searchText = this.eventFilter.searchText;
      return this.events.filter(function(item) {
        return item.name.indexOf(searchText) !== -1;
      });
    },
    sectionTitle: function() {
      if(this.eventFilter.module == 'all')
        suffix = 'All Events';
      else if(this.eventFilter.module == 'lib')
        suffix = 'Events in lib folder';
      else
        suffix = this.eventFilter.module + ' Module Events';

      return "Magento " + this.eventFilter.version + " - " + suffix + " (" + this.foundEvents.length + ")";
    }
  },
  mounted: function() {
    var vueApp = this;
    xhr.onload = function(e) {
      var uInt8Array = new Uint8Array(this.response);
      db = new SQL.Database(uInt8Array);
      var versions = db.exec("SELECT DISTINCT magento_version from events ORDER BY magento_version ASC");
      var modules = db.exec("SELECT DISTINCT magento_module from events where magento_module != 'lib'");
      vueApp.versions =  vueApp.getValues(versions);
      vueApp.modules =  vueApp.getValues(modules);
      vueApp.$set(vueApp.eventFilter, 'version', vueApp.versions[0]);
      vueApp.$set(vueApp.eventFilter, 'module', 'all');
      vueApp.getEvents();
    };
  },
  methods: {
    getEvents: function() {
      this.eventFilter.searchText = '';
      var query = "SELECT name, file_url, starting_line FROM events WHERE magento_version = '"+ this.eventFilter.version +"'";
      if(this.eventFilter.module !== 'all')
        query += " AND magento_module = '" + this.eventFilter.module + "'";

      this.events = this.getValues(db.exec(query), true);
    },
    getValues: function(sqlQueryResult, isEvents = false) {
      if (isEvents == false) {
        return sqlQueryResult[0].values.map(function(item){
          return item[0];
        });
      }
      else {
        version = this.eventFilter.version;
        return sqlQueryResult[0].values.map(function(item){
          return {
            name: item[0],
            location: item[1],
            githubUrl: "https://github.com/magento/magento2/blob/"+version+"/"+ item[1] + "#L" + item[2]
          };
        });
      }
    }
  }
});
