# **Peeramid front documentation**

## Development server

#### Installation

  1. Go to the "front" directory:
  
      ````
      cd front
      ````
  
  2. Install **Node.js** from the [official website](https://nodejs.org/en/).
  
  3. To install the packages, run:
     
     ````
     npm install && install.sh
     ````

#### Usage

  1. If new packages have been added or upgraded to a new version, re run:
     
     ````
     npm install && install.sh
     ````

  2. Edit the environment file:

      Open the "environment" file in the "src/environments/" directory, then replace 'api_url' and 'upload_url'.
      ````
      api_url: '/* your path to access the api */',
        // eg: 'http://localhost/Peeramid/back/web/api/'
      upload_url: '/* your path to access uploaded files */'
        // eg: 'http://localhost/Peeramid/back/web/upload/'
      ````

  3. To launch the development server, run:
  
      ````
      ng serve
      ````

      >Run this command in the "front" directory      

  4. To acces the platform, navigate to [http://localhost:4200/](http://localhost:4200/).

     *Hot reloading will reload the page on every change in the source files.*

## Production server

To build the platform, run:

````
ng build
````

To build in production, run:

````
ng build -prod
````

## Project specifications

This project is powered by **[Angular 4.3.0](https://angular.io/)**.

This project is build with **[Angular CLI](https://github.com/angular/angular-cli/blob/master/README.md)**.

To get more help on the CLI, run:
````
ng help
````

#

[![SOS Futur](../sosf_logo.png)](https://www.sos-futur.fr/)
###### powered by [SOS Futur](https://www.sos-futur.fr/)