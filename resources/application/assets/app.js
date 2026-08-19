import { Application } from '@hotwired/stimulus';
import '@hotwired/turbo';
import './styles/app.css';
import AlertDialogController from './controllers/alert_dialog_controller.js';
import PasswordVisibilityController from './controllers/password_visibility_controller.js';
import SidebarController from './controllers/sidebar_controller.js';

const application = Application.start();

application.register('alert-dialog', AlertDialogController);
application.register('password-visibility', PasswordVisibilityController);
application.register('sidebar', SidebarController);
