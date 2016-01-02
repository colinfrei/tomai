# Tomai

## What is Tomai?
Tomai is a tool to let you easily publish email with certain tags to a google group if you're using Google Apps. 

## Installation
You'll need:
- a PHP host
- a MySQL database
- admin access to a google apps account (TODO: list exact permissions required)
- a domain or subdomain available under HTTPS

Run the following to set up the project:

    git clone https://github.com/colinfrei/tomai.git

Copy the `app/config/parameters.yml.dist` file to `app/config/parameters.yml` and adjust the values as needed.

If you don't have composer yet, download composer using the following command:

    curl -s https://getcomposer.org/installer | php
    
Install the vendors using composer:
    
    php composer.phar install

### Setting up the application in Google
#### Setup
You'll need to set up Tomai as a project on Google to get the necessary credentials to access Google APIs. To do that,
go to https://console.developers.google.com/project, logged in as an admin user of your Google Apps domain, and click on
_Create project_. You'll be prompted to choose a project name, which can be whatever you like, and a Project ID will be generated automatically.

#### Enable APIs
Submit the form and you'll be forwarded to the Dashboard for that project, where you'll see the project ID and number in a box at the top left. Directly below that you'll see a blue box titled 'Use Google APIs'. Click on the link at the bottom of that box labelled _Enable and manage APIs_.
On the next page you'll want to search for and enable the APIs below. You may see prompts to create credentials while enabling these APIs. You can ignore them for now - we'll do so in a second.
Enable the following APIs: 
- Admin SDK
- Gmail API
- Groups Migration API
- Groups Settings API
- Google Cloud Pub/Sub
There may be some other APIs already enabled by default - these are not needed. You can disable them or ignore them, whatever you prefer.

#### Credentials
Next you'll need to set up credentials to access the APIs. Click on the _Credentials_ menu item on the left.

##### OAuth
First we'll set up the OAuth id, but to do that we need to configure the OAuth consent screen first. Click on the _OAuth consent screen_ tab and set the Product name there. You can set up the other fields as well, but they're not mandatory.
Now, click on `New credentials` and select `OAuth client ID` from the dropdown. Choose the 'Web application' radio button and enter a name.
For the Authorized JavaScript origins, set the domain you'll be running Tomai on. Make sure to use https here.
The authorized redirect URIs should be your domain, with the suffix /login/check-google, and using https, like this: `https://tomai.com/login/check-google`

Save the form and you'll receive your client ID and client secret - set these in your app/config/parameters.yml as the `google_oauth_client_id` and `google_oauth_client_secret` parameter respectively.
While you're there, set the `google_apps_domain` parameter to your domain (_tomai.com_)

##### Service Account
Next we'll set up the service account. For that, click on the menu button at the top left and choose 'Permissions', and then on the _Service accounts_ tab at the top, and then on the _Create service account_ button.
Give it a name and set the service account ID it generates as the `google_service_account_name` parameter in your app/config/parameters.yml file. Check the 'Furnish a new private key' checkbox and choose a json key, and check the 'Enable Google Apps Domain-wide Delegation' checkbox.
Click on Create and you'll be prompted to save the json key file - save it to your app/config folder, and set the filename as the `google_service_account_json_filename` variable in your app/config/parameters.yml file.
You don't need to set the _notasecret_ key password anywhere - that's the same for all keys and is set by default.
The last thing you need to set in this section is `google_service_account_sub_user' parameter in your paremeters.yml file - set that to the address of your google apps admin (probably your email address).
You'll also need to allow the service account to do stuff in the name of users - this has to be done in the Google Admin backend. Go to https://admin.google.com/liip.ch/AdminHome?chromeless=1#OGX:ManageOauthClients (or Security -> Show more -> Advanced settings -> Manage API client access) and add a new client. The Client Name is the Client ID that's listed in the JSON file (a string of numbers), for the scopes, copy this comma separated list of scopes:

    https://www.googleapis.com/auth/admin.directory.group,https://www.googleapis.com/auth/apps.groups.migration,https://www.googleapis.com/auth/apps.groups.settings,https://www.googleapis.com/auth/pubsub
