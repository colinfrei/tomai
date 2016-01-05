# Tomai
Tomai is a tool to let you easily publish email with certain tags to a google group if you're using Google Apps. 

# Installation
You'll need:
- a PHP host
- a MySQL database
- admin access to a google apps account (TODO: list exact permissions required)
- a domain or subdomain available under HTTPS (not necessary if you poll for new messages)

Run the following to set up the project:

    git clone https://github.com/colinfrei/tomai.git

Copy the `app/config/parameters.yml.dist` file to `app/config/parameters.yml` and adjust the values as needed.

If you don't have composer yet, download composer using the following command:

    curl -s https://getcomposer.org/installer | php
    
Install the vendors using composer:
    
    php composer.phar install

## Setting up the application in Google
### Setup
You'll need to set up Tomai as a project on Google to get the necessary credentials to access Google APIs. To do that,
go to https://console.developers.google.com/project, logged in as an admin user of your Google Apps domain, and click on
_Create project_. You'll be prompted to choose a project name, which can be whatever you like, and a Project ID will be generated automatically. Input this project id in your _app/config/parameters.yml_ file as the `google_project_id` parameter. While you're there, set the `google_apps_domain` parameter to the domain you're using for your google apps account (i.e. tomai.com).


### Enable APIs
Submit the form and you'll be forwarded to the Dashboard for that project, where you'll see the project ID and number in a box at the top left. Directly below that you'll see a blue box titled 'Use Google APIs'. Click on the link at the bottom of that box labelled _Enable and manage APIs_.
On the next page you'll want to search for and enable the APIs below. You may see prompts to create credentials while enabling these APIs. You can ignore them for now - we'll do so in a second.
Enable the following APIs: 
- Admin SDK
- Gmail API
- Groups Migration API
- Groups Settings API
- Google Cloud Pub/Sub
There may be some other APIs already enabled by default - these are not needed. You can disable them or ignore them, whatever you prefer.

### Credentials
Next you'll need to set up credentials to access the APIs. Click on the _Credentials_ menu item on the left.

#### OAuth
First we'll set up the OAuth id, but to do that we need to configure the OAuth consent screen first. Click on the _OAuth consent screen_ tab and set the Product name there. You can set up the other fields as well, but they're not mandatory.
Now, click on `New credentials` and select `OAuth client ID` from the dropdown. Choose the 'Web application' radio button and enter a name.
For the Authorized JavaScript origins, set the domain you'll be running Tomai on. Make sure to use https here if your site will be running under https.
The authorized redirect URIs should be your domain, with the suffix /login/check-google, like this: `https://tomai.com/login/check-google`

Save the form and you'll receive your client ID and client secret - set these in your app/config/parameters.yml as the `google_oauth_client_id` and `google_oauth_client_secret` parameter respectively.

#### Service Account
Next we'll set up the service account. For that, click on the menu button at the top left and choose 'Permissions', and then on the _Service accounts_ tab at the top, and then on the _Create service account_ button.

Give your service account a name (anything will do, it's mostly for you to identify it) and check the 'Furnish a new private key' checkbox, choosing json as the key format. Also, make sure the 'Enable Google Apps Domain-wide Delegation' checkbox is checked.

Click on Create and you'll be prompted to save the json key file. Save it to your app/config folder, and set the filename as the `google_service_account_json_filename` parameter in your _app/config/parameters.yml_ file.

The last thing you need to set in this section is `google_service_account_sub_user` parameter in your parameters.yml file - set that to the address of your google apps admin (probably your email address).

You now need to grant the service account permission to do stuff in the name of users - this has to be done in the Google Admin backend. Go to https://admin.google.com/liip.ch/AdminHome?chromeless=1#OGX:ManageOauthClients (or admin.google.com -> Security -> Show more -> Advanced settings -> Manage API client access) and add a new client. The Client Name is the Client ID that's listed in the JSON file (a string of numbers). For the scopes, copy this comma separated list of scopes:

    https://www.googleapis.com/auth/admin.directory.group,https://www.googleapis.com/auth/apps.groups.migration,https://www.googleapis.com/auth/apps.groups.settings,https://www.googleapis.com/auth/pubsub
    
## Set up the PubSub connection
So your site should mostly work now - if you go to the URL then the first login page should show up (if not, make sure you cleared your cache by running `app/console cache:clear --env=prod`). The last thing that needs to be set up is the connection to get notifications about new messages.

You can choose if you want to have notifications about new messages pushed to your server immediately, or want to poll the Google servers regularly to ask if there are new messages. In most cases you'll want to use push, with two exceptions:
- your development setup/server is running locally and isn't easily accessible from the internet
- you don't have an SSL/TLS certificate - Google requires that the URL for push messages be under HTTPS.
 
Once you've decided, run the following command to set up everything necessary:
    app/console tomai:google-pubsub-setup --env=prod
    
You'll be prompted to choose either the push or pull method during the commands execution.

## Set up the database
Run the following command to set up your database schema:
    app/console doctrine:schema:create --env=prod

## That's it!
You're done! 
To see if everything's working, log in and set up a label to be copied to your Google group, label an email or two with that label, and see if the email shows up in the google group 5-10 minutes later.

